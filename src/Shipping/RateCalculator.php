<?php

namespace Lilleprinsen\Cargonizer\Shipping;

use Lilleprinsen\Cargonizer\Domain\Contracts\PriceModifierInterface;
use Lilleprinsen\Cargonizer\Domain\DTO\RateQuoteRequest;
use Lilleprinsen\Cargonizer\Shipping\Modifiers\ConfiguredPricingModifier;

final class RateCalculator
{
    /** @var array<int,PriceModifierInterface> */
    private array $priceModifiers;

    /**
     * @param array<int,PriceModifierInterface> $priceModifiers
     */
    public function __construct(array $priceModifiers = [])
    {
        $this->priceModifiers = $priceModifiers === [] ? [new ConfiguredPricingModifier()] : $priceModifiers;
    }

    /**
     * @param array<string,mixed> $pricing
     */
    public function calculate(float $baseRate, array $pricing, RateQuoteRequest $request): float
    {
        $rate = $baseRate;

        foreach ($this->priceModifiers as $modifier) {
            $rate = $modifier->modify($rate, $pricing, $request);
        }

        return $rate;
    }
}
