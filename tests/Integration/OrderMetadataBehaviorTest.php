<?php

namespace Lilleprinsen\Cargonizer\Tests\Integration;

use Lilleprinsen\Cargonizer\Checkout\CheckoutService;
use PHPUnit\Framework\TestCase;

final class OrderMetadataBehaviorTest extends TestCase
{
    public function testPersistOrderMetadataUsesSessionQuoteAndShipmentIds(): void
    {
        $GLOBALS['__wc_session'] = [
            'lp_cargonizer_quote_id' => 'Q-100',
            'lp_cargonizer_shipment_id' => 'S-100',
        ];

        $order = new \WC_Order();
        (new CheckoutService())->persistOrderMetadata($order, []);

        self::assertSame('Q-100', $order->meta[CheckoutService::META_QUOTE_ID]);
        self::assertSame('S-100', $order->meta[CheckoutService::META_SHIPMENT_ID]);
    }
}
