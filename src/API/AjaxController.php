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
}
