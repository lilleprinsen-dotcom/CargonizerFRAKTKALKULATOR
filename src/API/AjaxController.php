<?php

namespace Lilleprinsen\Cargonizer\API;

use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;

final class AjaxController
{
    private ShippingMethodRegistry $shippingRegistry;

    public function __construct(ShippingMethodRegistry $shippingRegistry)
    {
        $this->shippingRegistry = $shippingRegistry;
    }

    public function fetchMethods(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        check_ajax_referer('lp_cargonizer_fetch_methods', 'nonce');

        $async = !isset($_REQUEST['sync']) || sanitize_text_field((string) wp_unslash($_REQUEST['sync'])) !== '1';
        if ($async && $this->shippingRegistry->refreshFromCargonizerAsync()) {
            wp_send_json_success([
                'queued' => true,
                'message' => __('Shipping method refresh queued in Action Scheduler.', 'lp-cargonizer'),
            ]);
        }

        $data = $this->shippingRegistry->refreshFromCargonizer();

        wp_send_json_success([
            'queued' => false,
            'methods' => $data,
        ]);
    }

    public function getOrderEstimateData(): void
    {
        $this->assertAjaxCapabilityAndNonce('lp_cargonizer_get_order_estimate_data');

        $order = $this->getOrderFromRequest();
        if (!$order instanceof \WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'lp-cargonizer')], 404);
        }

        $shippingTotal = (float) $order->get_shipping_total();
        $package = [
            'destination' => [
                'country' => (string) $order->get_shipping_country(),
                'state' => (string) $order->get_shipping_state(),
                'postcode' => (string) $order->get_shipping_postcode(),
                'city' => (string) $order->get_shipping_city(),
            ],
            'value' => (float) $order->get_total(),
            'contents_cost' => (float) $order->get_subtotal(),
            'weight' => 0.0,
        ];

        $lineItems = [];
        $defaultPackages = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();
            $separatePackage = $product instanceof \WC_Product && $product->get_meta('_wildrobot_separate_package_for_product') === 'yes';
            if ($separatePackage) {
                $packageName = trim((string) $product->get_meta('_wildrobot_separate_package_for_product_name'));
                if ($packageName === '') {
                    $packageName = (string) $item->get_name();
                }

                $weight = (float) $product->get_meta('_weight');
                $length = (float) $product->get_meta('_length');
                $width = (float) $product->get_meta('_width');
                $height = (float) $product->get_meta('_height');
                $quantity = max(1, (int) $item->get_quantity());

                if ($weight > 0 && $length > 0 && $width > 0 && $height > 0) {
                    for ($i = 0; $i < $quantity; $i++) {
                        $defaultPackages[] = [
                            'description' => $packageName,
                            'length' => $length,
                            'width' => $width,
                            'height' => $height,
                            'weight' => $weight,
                        ];
                    }
                }
            }

            $lineItems[] = [
                'name' => (string) $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'total' => wc_price((float) $item->get_total(), ['currency' => $order->get_currency()]),
                'separate_package' => $separatePackage,
                'separate_package_name' => $product instanceof \WC_Product ? (string) $product->get_meta('_wildrobot_separate_package_for_product_name') : '',
            ];
        }

        $recipientName = trim((string) $order->get_formatted_shipping_full_name());
        if ($recipientName === '') {
            $recipientName = trim((string) $order->get_formatted_billing_full_name());
        }

        $addressLine1 = trim((string) $order->get_shipping_address_1());
        $addressLine2 = trim((string) $order->get_shipping_address_2());
        $postal = trim((string) $order->get_shipping_postcode() . ' ' . (string) $order->get_shipping_city());

        wp_send_json_success([
            'order_id' => $order->get_id(),
            'shipping_total' => $shippingTotal,
            'package' => $package,
            'methods' => $this->shippingRegistry->all(),
            'order_summary' => [
                'order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'order_date' => $order->get_date_created() ? $order->get_date_created()->date_i18n('d.m.Y H:i') : '',
                'total_formatted' => wc_price((float) $order->get_total(), ['currency' => $order->get_currency()]),
            ],
            'recipient' => [
                'name' => $recipientName,
                'address' => trim($addressLine1 . ($addressLine2 !== '' ? ', ' . $addressLine2 : '')),
                'postal' => $postal,
                'email' => (string) $order->get_billing_email(),
                'phone' => (string) $order->get_billing_phone(),
            ],
            'packages' => $defaultPackages,
            'lines' => $lineItems,
        ]);
    }

    public function getShippingOptions(): void
    {
        $this->assertAjaxCapabilityAndNonce('lp_cargonizer_get_shipping_options');

        wp_send_json_success([
            'methods' => $this->shippingRegistry->all(),
        ]);
    }

    public function runBulkEstimate(): void
    {
        $this->assertAjaxCapabilityAndNonce('lp_cargonizer_run_bulk_estimate');

        $order = $this->getOrderFromRequest();
        if (!$order instanceof \WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'lp-cargonizer')], 404);
        }

        $methods = $this->shippingRegistry->all();

        $selectedMethodIds = isset($_REQUEST['method_ids']) ? json_decode(wp_unslash((string) $_REQUEST['method_ids']), true) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $selectedMethodIds = is_array($selectedMethodIds) ? array_map('sanitize_text_field', $selectedMethodIds) : [];

        if ($selectedMethodIds !== []) {
            $methods = array_values(array_filter($methods, static function ($method) use ($selectedMethodIds): bool {
                if (!is_array($method)) {
                    return false;
                }

                return in_array((string) ($method['method_id'] ?? ''), $selectedMethodIds, true);
            }));
        }

        $package = [
            'destination' => [
                'country' => (string) $order->get_shipping_country(),
                'state' => (string) $order->get_shipping_state(),
                'postcode' => (string) $order->get_shipping_postcode(),
                'city' => (string) $order->get_shipping_city(),
            ],
            'value' => (float) $order->get_total(),
            'contents_cost' => (float) $order->get_subtotal(),
            'weight' => 0.0,
        ];

        $customPackages = isset($_REQUEST['packages']) ? json_decode(wp_unslash((string) $_REQUEST['packages']), true) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (is_array($customPackages) && $customPackages !== []) {
            $normalized = [];
            $totalWeight = 0.0;
            foreach ($customPackages as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $length = max(0.0, (float) ($item['length'] ?? 0));
                $width = max(0.0, (float) ($item['width'] ?? 0));
                $height = max(0.0, (float) ($item['height'] ?? 0));
                $weight = max(0.0, (float) ($item['weight'] ?? 0));

                if ($length <= 0 || $width <= 0 || $height <= 0 || $weight <= 0) {
                    continue;
                }

                $normalized[] = [
                    'description' => sanitize_text_field((string) ($item['description'] ?? 'Pakke')),
                    'length' => $length,
                    'width' => $width,
                    'height' => $height,
                    'weight' => $weight,
                    'volume' => ($length * $width * $height) / 1000000,
                ];
                $totalWeight += $weight;
            }

            if ($normalized !== []) {
                $package['weight'] = $totalWeight;
                $package['colli'] = $normalized;
            }
        }

        $jobs = [];
        $preparedMethods = [];
        $methodOverrides = isset($_REQUEST['method_overrides']) ? json_decode(wp_unslash((string) $_REQUEST['method_overrides']), true) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $methodOverrides = is_array($methodOverrides) ? $methodOverrides : [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $override = isset($methodOverrides[(string) ($method['method_id'] ?? '')]) && is_array($methodOverrides[(string) ($method['method_id'] ?? '')])
                ? $methodOverrides[(string) ($method['method_id'] ?? '')]
                : [];
            if ($override !== []) {
                $method['service_partner'] = sanitize_text_field((string) ($override['service_partner'] ?? ''));
                $method['sms_enabled'] = !empty($override['sms_enabled']) ? 'yes' : 'no';
            }

            $jobs[] = [
                'method' => $method,
                'package' => $package,
            ];
            $preparedMethods[] = $method;
        }

        $jobId = $this->shippingRegistry->enqueueBulkEstimate($jobs);
        if ($jobId !== null) {
            wp_send_json_success([
                'queued' => true,
                'job_id' => $jobId,
            ]);
        }

        $results = [];
        $recipient = [
            'name' => trim((string) $order->get_formatted_shipping_full_name()) ?: trim((string) $order->get_formatted_billing_full_name()),
            'address1' => (string) $order->get_shipping_address_1(),
            'address2' => (string) $order->get_shipping_address_2(),
            'postcode' => (string) $order->get_shipping_postcode(),
            'city' => (string) $order->get_shipping_city(),
            'country' => (string) $order->get_shipping_country(),
        ];

        foreach ($preparedMethods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $result = $this->shippingRegistry->resolveAdminEstimate($method, $package, $recipient);
            $requirements = isset($result['estimate']['requirements']) && is_array($result['estimate']['requirements'])
                ? $result['estimate']['requirements']
                : [];
            if (!empty($requirements['servicepartner_required'])) {
                $result['servicepartner_options'] = $this->shippingRegistry->getServicepartnerOptions($method, $package['destination']);
            }

            $results[] = $result;
        }

        wp_send_json_success([
            'queued' => false,
            'results' => $results,
        ]);
    }

    public function getServicepartnerOptions(): void
    {
        $this->assertAjaxCapabilityAndNonce('lp_cargonizer_get_servicepartner_options');
        $methodId = sanitize_text_field((string) ($_REQUEST['method_id'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $method = $this->shippingRegistry->getMethodConfigByMethodId($methodId);
        $destination = isset($_REQUEST['destination']) ? json_decode(wp_unslash((string) $_REQUEST['destination']), true) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $destination = is_array($destination) ? $destination : [];
        $partners = $this->shippingRegistry->getServicepartnerOptions($method, $destination);

        wp_send_json_success([
            'servicepartners' => $partners,
            'debug' => [
                'method_id' => $methodId,
                'destination' => $destination,
                'count' => count($partners),
            ],
        ]);
    }

    private function assertAjaxCapabilityAndNonce(string $nonceAction): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'lp-cargonizer')], 403);
        }

        check_ajax_referer($nonceAction, 'nonce');
    }

    private function getOrderFromRequest(): ?\WC_Order
    {
        if (!function_exists('wc_get_order')) {
            return null;
        }

        $orderId = absint($_REQUEST['order_id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        $order = wc_get_order($orderId);

        return $order instanceof \WC_Order ? $order : null;
    }
}
