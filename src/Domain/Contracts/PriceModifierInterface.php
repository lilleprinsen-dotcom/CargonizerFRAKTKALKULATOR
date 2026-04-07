<?php

namespace Lilleprinsen\Cargonizer\Domain\Contracts;

use Lilleprinsen\Cargonizer\Domain\DTO\RateQuoteRequest;

interface PriceModifierInterface
{
    /**
     * @param array<string,mixed> $pricingConfig
     */
    public function modify(float $baseRate, array $pricingConfig, RateQuoteRequest $request): float;
}
