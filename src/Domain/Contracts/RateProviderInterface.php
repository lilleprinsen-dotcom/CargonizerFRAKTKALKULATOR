<?php

namespace Lilleprinsen\Cargonizer\Domain\Contracts;

use Lilleprinsen\Cargonizer\Domain\DTO\RateQuote;
use Lilleprinsen\Cargonizer\Domain\DTO\RateQuoteRequest;

interface RateProviderInterface
{
    public function getRateQuote(RateQuoteRequest $request): ?RateQuote;
}
