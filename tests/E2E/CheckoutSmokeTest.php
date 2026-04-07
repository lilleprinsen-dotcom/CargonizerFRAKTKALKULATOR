<?php

namespace Lilleprinsen\Cargonizer\Tests\E2E;

use Lilleprinsen\Cargonizer\API\CargonizerClient;
use Lilleprinsen\Cargonizer\Domain\Contracts\RateProviderInterface;
use Lilleprinsen\Cargonizer\Domain\DTO\RateQuote;
use Lilleprinsen\Cargonizer\Domain\DTO\RateQuoteRequest;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsRepository;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;
use Lilleprinsen\Cargonizer\Shipping\RateCalculator;
use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;
use PHPUnit\Framework\TestCase;

final class CheckoutSmokeTest extends TestCase
{
    /**
     * @dataProvider smokeCases
     */
    public function testRepresentativeZonesAndMethodsCheckoutSmoke(float $baseRate, float $expected): void
    {
        $settingsService = new SettingsService(new SettingsRepository());
        $settingsService->save([
            'discount_percent' => 10,
            'fuel_percent' => 5,
            'vat_percent' => 25,
            'rounding_precision' => 2,
            'available_methods' => [],
        ]);

        $provider = new class($baseRate) implements RateProviderInterface {
            private float $rate;
            public function __construct(float $rate) { $this->rate = $rate; }
            public function getRateQuote(RateQuoteRequest $request): ?RateQuote { return new RateQuote($this->rate, 'NOK', 'Q-1'); }
        };

        $registry = new ShippingMethodRegistry($settingsService, new CargonizerClient($settingsService), $provider, new RateCalculator());

        $rate = $registry->resolveRate([
            'method_id' => 'lp_cargonizer_test',
            'agreement_id' => 'A',
            'product_id' => 'P',
        ], [
            'destination' => ['country' => 'NO', 'postcode' => '7000'],
            'contents_cost' => 1000,
        ]);

        self::assertSame($expected, $rate);
    }

    public function smokeCases(): array
    {
        return [
            'domestic-home-delivery' => [100.0, 118.13],
            'domestic-pickup-point' => [79.0, 93.32],
            'cross-border-parcel' => [140.0, 165.38],
        ];
    }
}
