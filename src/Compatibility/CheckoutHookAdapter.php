<?php

namespace Lilleprinsen\Cargonizer\Compatibility;

use Lilleprinsen\Cargonizer\Checkout\CheckoutService;
use WC_Order;

final class CheckoutHookAdapter
{
    private CheckoutService $checkoutService;

    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    /**
     * @param mixed ...$args
     */
    public function persistOrderMetadataFromHook(...$args): void
    {
        $order = $args[0] ?? null;
        $data = $args[1] ?? [];

        if (!$order instanceof WC_Order) {
            return;
        }

        if (!is_array($data)) {
            $data = [];
        }

        $this->checkoutService->persistOrderMetadata($order, $data);
    }
}
