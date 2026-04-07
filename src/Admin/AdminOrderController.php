<?php

namespace Lilleprinsen\Cargonizer\Admin;

use Lilleprinsen\Cargonizer\Checkout\CheckoutService;

final class AdminOrderController
{
    public function renderOrderEstimatorButton(): void
    {
        if (!$this->isSingleShopOrderEditScreen() || !current_user_can('manage_woocommerce')) {
            return;
        }

        echo '<p class="form-field form-field-wide lp-cargonizer-order-estimator">';
        echo '<button type="button" class="button button-secondary" id="lp-cargonizer-open-estimator">';
        echo esc_html__('Estimer fraktkostnad', 'lp-cargonizer');
        echo '</button>';
        echo '</p>';
    }

    public function renderOrderEstimatorModal(): void
    {
        if (!$this->isSingleShopOrderEditScreen() || !current_user_can('manage_woocommerce')) {
            return;
        }

        $orderId = isset($_GET['id']) ? absint($_GET['id']) : absint($_GET['post'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($orderId <= 0) {
            return;
        }

        $nonces = [
            'get_order_estimate_data' => wp_create_nonce('lp_cargonizer_get_order_estimate_data'),
            'get_shipping_options' => wp_create_nonce('lp_cargonizer_get_shipping_options'),
            'run_bulk_estimate' => wp_create_nonce('lp_cargonizer_run_bulk_estimate'),
            'get_servicepartner_options' => wp_create_nonce('lp_cargonizer_get_servicepartner_options'),
        ];

        echo '<div id="lp-cargonizer-estimator-modal" class="lp-cargonizer-estimator-modal" style="display:none;"';
        echo ' data-order-id="' . esc_attr((string) $orderId) . '"';
        echo ' data-get-order-estimate-data-nonce="' . esc_attr($nonces['get_order_estimate_data']) . '"';
        echo ' data-get-shipping-options-nonce="' . esc_attr($nonces['get_shipping_options']) . '"';
        echo ' data-run-bulk-estimate-nonce="' . esc_attr($nonces['run_bulk_estimate']) . '"';
        echo ' data-get-servicepartner-options-nonce="' . esc_attr($nonces['get_servicepartner_options']) . '">';
        echo '<div class="lp-cargonizer-estimator-modal__content">';
        echo '<h3>' . esc_html__('Cargonizer fraktestimat', 'lp-cargonizer') . '</h3>';
        echo '<div class="lp-cargonizer-estimator-results"></div>';
        echo '</div></div>';
    }

    public function registerLegacyOrderColumns(array $columns): array
    {
        return $this->injectCargonizerColumn($columns);
    }

    public function registerHposOrderColumns(array $columns): array
    {
        return $this->injectCargonizerColumn($columns);
    }

    public function renderLegacyOrderColumn(string $column, int $postId): void
    {
        if ($column !== 'lp_cargonizer_shipment') {
            return;
        }

        $this->renderShipmentMetaForOrderId($postId);
    }

    /**
     * @param int|\WC_Order $order
     */
    public function renderHposOrderColumn(string $column, $order): void
    {
        if ($column !== 'lp_cargonizer_shipment') {
            return;
        }

        $orderId = $order instanceof \WC_Order ? $order->get_id() : (int) $order;
        $this->renderShipmentMetaForOrderId($orderId);
    }

    /**
     * @param \WC_Order|\WC_Order_Refund $order
     */
    public function renderOrderShipmentPanel($order): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }

        $this->renderShipmentMeta($order);
    }

    private function injectCargonizerColumn(array $columns): array
    {
        $newColumns = [];
        foreach ($columns as $key => $label) {
            if ($key === 'order_total') {
                $newColumns['lp_cargonizer_shipment'] = __('Cargonizer', 'lp-cargonizer');
            }
            $newColumns[$key] = $label;
        }

        if (!isset($newColumns['lp_cargonizer_shipment'])) {
            $newColumns['lp_cargonizer_shipment'] = __('Cargonizer', 'lp-cargonizer');
        }

        return $newColumns;
    }

    private function renderShipmentMetaForOrderId(int $orderId): void
    {
        if (!function_exists('wc_get_order')) {
            echo '—';
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            echo '—';
            return;
        }

        $this->renderShipmentMeta($order);
    }

    private function renderShipmentMeta(\WC_Order $order): void
    {
        $quoteId = $order->get_meta(CheckoutService::META_QUOTE_ID, true);
        $shipmentId = $order->get_meta(CheckoutService::META_SHIPMENT_ID, true);

        if ($quoteId === '' && $shipmentId === '') {
            echo '—';
            return;
        }

        $parts = [];
        if ($quoteId !== '') {
            $parts[] = sprintf(
                '%s: %s',
                esc_html__('Quote', 'lp-cargonizer'),
                esc_html((string) $quoteId)
            );
        }

        if ($shipmentId !== '') {
            $parts[] = sprintf(
                '%s: %s',
                esc_html__('Shipment', 'lp-cargonizer'),
                esc_html((string) $shipmentId)
            );
        }

        echo wp_kses_post(implode('<br>', $parts));
    }

    private function isSingleShopOrderEditScreen(): bool
    {
        if (!is_admin() || !function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if (!$screen instanceof \WP_Screen) {
            return false;
        }

        if ($screen->base === 'post' && $screen->post_type === 'shop_order') {
            return true;
        }

        if ($screen->id === 'woocommerce_page_wc-orders' && isset($_GET['action']) && sanitize_key((string) wp_unslash($_GET['action'])) === 'edit') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return true;
        }

        return false;
    }
}
