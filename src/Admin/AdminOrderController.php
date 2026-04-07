<?php

namespace Lilleprinsen\Cargonizer\Admin;

use Lilleprinsen\Cargonizer\Checkout\CheckoutService;

final class AdminOrderController
{
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
}
