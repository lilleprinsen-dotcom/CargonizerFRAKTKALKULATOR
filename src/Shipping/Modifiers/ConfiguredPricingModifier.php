<?php

namespace Lilleprinsen\Cargonizer\Shipping\Modifiers;

use Lilleprinsen\Cargonizer\Domain\Contracts\PriceModifierInterface;
use Lilleprinsen\Cargonizer\Domain\DTO\RateQuoteRequest;

final class ConfiguredPricingModifier implements PriceModifierInterface
{
    /**
     * @param array<string,mixed> $pricingConfig
     */
    public function modify(float $baseRate, array $pricingConfig, RateQuoteRequest $request): float
    {
        $rate = max(0.0, $baseRate);

        $discountPercent = $this->clampPercent($pricingConfig['discount_percent'] ?? 0);
        if ($discountPercent > 0.0) {
            $rate *= (1 - ($discountPercent / 100));
        }

        $fuelPercent = $this->clampPercent($pricingConfig['fuel_percent'] ?? 0);
        if ($fuelPercent > 0.0) {
            $rate *= (1 + ($fuelPercent / 100));
        }

        $tollFee = $this->asPositiveFloat($pricingConfig['toll_fee'] ?? 0);
        $handlingFee = $this->asPositiveFloat($pricingConfig['handling_fee'] ?? 0);
        $rate += ($tollFee + $handlingFee);

        $vatPercent = $this->clampPercent($pricingConfig['vat_percent'] ?? 0);
        if ($vatPercent > 0.0) {
            $rate *= (1 + ($vatPercent / 100));
        }

        $precision = (int) ($pricingConfig['rounding_precision'] ?? 2);
        $precision = max(0, min(4, $precision));

        return round($rate, $precision);
    }

    /**
     * @param mixed $value
     */
    private function clampPercent($value): float
    {
        $percent = is_numeric($value) ? (float) $value : 0.0;

        return max(0.0, min(100.0, $percent));
    }

    /**
     * @param mixed $value
     */
    private function asPositiveFloat($value): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        return max(0.0, $number);
    }
}
