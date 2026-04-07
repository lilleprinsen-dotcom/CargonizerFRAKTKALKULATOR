<?php

namespace Lilleprinsen\Cargonizer\Checkout;

use WC_Order;

final class CheckoutService
{
    public const META_QUOTE_ID = '_lp_cargonizer_quote_id';
    public const META_SHIPMENT_ID = '_lp_cargonizer_shipment_id';

    /**
     * @param array<string,mixed> $data
     */
    public function persistOrderMetadata(WC_Order $order, array $data = []): void
    {
        unset($data);

        $shipmentMeta = $this->getSessionValue('lp_cargonizer_shipment_id');
        $quoteMeta = $this->getSessionValue('lp_cargonizer_quote_id');

        if ($shipmentMeta === null && $quoteMeta === null) {
            return;
        }

        if ($quoteMeta !== null) {
            $order->update_meta_data(self::META_QUOTE_ID, $quoteMeta);
        }

        if ($shipmentMeta !== null) {
            $order->update_meta_data(self::META_SHIPMENT_ID, $shipmentMeta);
        }
    }

    private function getSessionValue(string $key): ?string
    {
        if (!function_exists('WC')) {
            return null;
        }

        $woocommerce = WC();
        if (!isset($woocommerce->session) || $woocommerce->session === null) {
            return null;
        }

        $value = $woocommerce->session->get($key);
        if (!is_scalar($value) || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
