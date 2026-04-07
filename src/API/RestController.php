<?php

namespace Lilleprinsen\Cargonizer\API;

use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;
use WP_REST_Request;

final class RestController
{
    private ShippingMethodRegistry $shippingRegistry;

    public function __construct(ShippingMethodRegistry $shippingRegistry)
    {
        $this->shippingRegistry = $shippingRegistry;
    }

    public function registerRoutes(): void
    {
        register_rest_route('lp-cargonizer/v1', '/shipping-methods', [
            'methods' => 'GET',
            'permission_callback' => static fn (): bool => current_user_can('manage_woocommerce'),
            'callback' => [$this, 'listShippingMethods'],
        ]);
    }

    public function listShippingMethods(WP_REST_Request $request): array
    {
        unset($request);

        return [
            'methods' => $this->shippingRegistry->all(),
        ];
    }
}
