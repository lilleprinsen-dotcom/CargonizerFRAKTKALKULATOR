<?php

namespace Lilleprinsen\Cargonizer\Tests\Unit;

use Lilleprinsen\Cargonizer\Domain\Contracts\PriceModifierInterface;
use Lilleprinsen\Cargonizer\Domain\DTO\RateQuoteRequest;
use Lilleprinsen\Cargonizer\Shipping\RateCalculator;
use PHPUnit\Framework\TestCase;

final class RateCalculatorTest extends TestCase
{
    public function testRunsAllModifiersInOrder(): void
    {
        $m1 = new class implements PriceModifierInterface {
            public function modify(float $baseRate, array $pricing, RateQuoteRequest $request): float { return $baseRate + 10; }
        };
        $m2 = new class implements PriceModifierInterface {
            public function modify(float $baseRate, array $pricing, RateQuoteRequest $request): float { return $baseRate * 2; }
        };

        $calculator = new RateCalculator([$m1, $m2]);
        $req = new RateQuoteRequest('a', 'p', []);

        self::assertSame(30.0, $calculator->calculate(5.0, [], $req));
    }
}
