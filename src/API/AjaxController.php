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
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();
            $lineItems[] = [
                'name' => (string) $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'total' => wc_price((float) $item->get_total(), ['currency' => $order->get_currency()]),
                'separate_package' => $product instanceof \WC_Product && $product->get_meta('_wildrobot_separate_package_for_product') === 'yes',
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
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $jobs[] = [
                'method' => $method,
                'package' => $package,
            ];
        }

        $jobId = $this->shippingRegistry->enqueueBulkEstimate($jobs);
        if ($jobId !== null) {
            wp_send_json_success([
                'queued' => true,
                'job_id' => $jobId,
            ]);
        }

        $results = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $results[] = [
                'method_id' => (string) ($method['method_id'] ?? ''),
                'title' => (string) ($method['title'] ?? ($method['method_id'] ?? '')),
                'rate' => $this->shippingRegistry->resolveRate($method, $package),
            ];
        }

        wp_send_json_success([
            'queued' => false,
            'results' => $results,
        ]);
    }

    public function getServicepartnerOptions(): void
    {
        $this->assertAjaxCapabilityAndNonce('lp_cargonizer_get_servicepartner_options');

        $partners = [];
        foreach ($this->shippingRegistry->all() as $method) {
            if (!is_array($method)) {
                continue;
            }

            $agreementId = sanitize_text_field((string) ($method['agreement_id'] ?? ''));
            if ($agreementId === '') {
                continue;
            }

            $partners[$agreementId] = [
                'agreement_id' => $agreementId,
                'name' => sanitize_text_field((string) ($method['agreement_name'] ?? $agreementId)),
            ];
        }

        wp_send_json_success([
            'servicepartners' => array_values($partners),
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
