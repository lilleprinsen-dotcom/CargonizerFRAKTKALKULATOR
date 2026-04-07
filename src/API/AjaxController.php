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

        wp_send_json_success([
            'order_id' => $order->get_id(),
            'shipping_total' => $shippingTotal,
            'package' => $package,
            'methods' => $this->shippingRegistry->all(),
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
