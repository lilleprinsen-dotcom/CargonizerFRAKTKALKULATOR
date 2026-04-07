<?php

namespace Lilleprinsen\Cargonizer\Tests\Integration;

use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;
use Lilleprinsen\Cargonizer\Shipping\WooShippingIntegration;
use PHPUnit\Framework\TestCase;

final class WooShippingRegistrationTest extends TestCase
{
    public function testRegisterMethodsMergesRegistryMap(): void
    {
        $registry = $this->createMock(ShippingMethodRegistry::class);
        $registry->method('getMethodClassMap')->willReturn(['lp_cargonizer_1_2' => 'MethodClass']);

        $integration = new WooShippingIntegration($registry);
        $methods = $integration->registerMethods(['flat_rate' => 'WC_Shipping_Flat_Rate']);

        self::assertSame('MethodClass', $methods['lp_cargonizer_1_2']);
        self::assertSame('WC_Shipping_Flat_Rate', $methods['flat_rate']);
    }
}
