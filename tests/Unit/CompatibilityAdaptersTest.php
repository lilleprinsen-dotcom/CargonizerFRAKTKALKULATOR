<?php

namespace Lilleprinsen\Cargonizer\Tests\Unit;

use Lilleprinsen\Cargonizer\Admin\AdminOrderController;
use Lilleprinsen\Cargonizer\Checkout\CheckoutService;
use Lilleprinsen\Cargonizer\Compatibility\CheckoutHookAdapter;
use Lilleprinsen\Cargonizer\Compatibility\OrderHooksAdapter;
use PHPUnit\Framework\TestCase;

final class CompatibilityAdaptersTest extends TestCase
{
    public function testCheckoutAdapterPersistsMetadataWithOrderArg(): void
    {
        $GLOBALS['__wc_session'] = ['lp_cargonizer_quote_id' => 'Q-1'];

        $order = new \WC_Order();
        $adapter = new CheckoutHookAdapter(new CheckoutService());
        $adapter->persistOrderMetadataFromHook($order, []);

        self::assertSame('Q-1', $order->meta[CheckoutService::META_QUOTE_ID]);
    }

    public function testOrderAdapterRegistersWooColumnHooks(): void
    {
        $GLOBALS['__wp_filters'] = [];
        $adapter = new OrderHooksAdapter(new AdminOrderController());
        $adapter->registerOrderColumnHooks();

        self::assertArrayHasKey('manage_edit-shop_order_columns', $GLOBALS['__wp_filters']);
        self::assertArrayHasKey('manage_woocommerce_page_wc-orders_custom_column', $GLOBALS['__wp_filters']);
    }
}
