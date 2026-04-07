<?php

namespace Lilleprinsen\Cargonizer\Shipping;

final class RateCalculator
{
    /**
     * @param array<string,mixed> $pricing
     */
    public function calculate(float $baseRate, array $pricing): float
    {
        $rate = max(0.0, $baseRate);

        $discountPercent = $this->clampPercent($pricing['discount_percent'] ?? 0);
        if ($discountPercent > 0.0) {
            $rate *= (1 - ($discountPercent / 100));
        }

        $fuelPercent = $this->clampPercent($pricing['fuel_percent'] ?? 0);
        if ($fuelPercent > 0.0) {
            $rate *= (1 + ($fuelPercent / 100));
        }

        $tollFee = $this->asPositiveFloat($pricing['toll_fee'] ?? 0);
        $handlingFee = $this->asPositiveFloat($pricing['handling_fee'] ?? 0);
        $rate += ($tollFee + $handlingFee);

        $vatPercent = $this->clampPercent($pricing['vat_percent'] ?? 0);
        if ($vatPercent > 0.0) {
            $rate *= (1 + ($vatPercent / 100));
        }

        $precision = (int) ($pricing['rounding_precision'] ?? 2);
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
