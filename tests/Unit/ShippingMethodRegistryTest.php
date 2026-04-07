<?php

namespace Lilleprinsen\Cargonizer\Tests\Unit;

use Lilleprinsen\Cargonizer\API\CargonizerClient;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;
use Lilleprinsen\Cargonizer\Shipping\RateCalculator;
use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;
use PHPUnit\Framework\TestCase;

final class ShippingMethodRegistryTest extends TestCase
{
    public function testGetMethodConfigByMethodIdReturnsMatchingMethod(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getSettings')->willReturn([
            'available_methods' => [
                [
                    'instance_id' => 7,
                    'method_id' => 'lp_cargonizer_1_2',
                    'agreement_id' => '1',
                    'product_id' => '2',
                    'title' => 'Pickup',
                    'enabled' => 'yes',
                ],
            ],
        ]);

        $client = $this->createMock(CargonizerClient::class);
        $calculator = $this->createMock(RateCalculator::class);
        $registry = new ShippingMethodRegistry($settings, $client, $client, $calculator);

        $method = $registry->getMethodConfigByMethodId('lp_cargonizer_1_2');

        self::assertSame(7, $method['instance_id']);
        self::assertSame('Pickup', $method['title']);
    }
}
