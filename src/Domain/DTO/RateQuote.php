<?php

namespace Lilleprinsen\Cargonizer\Domain\DTO;

final class RateQuote
{
    private float $price;
    private string $currencyCode;
    private ?string $quoteId;

    public function __construct(float $price, string $currencyCode = 'NOK', ?string $quoteId = null)
    {
        $this->price = $price;
        $this->currencyCode = $currencyCode;
        $this->quoteId = $quoteId;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function getQuoteId(): ?string
    {
        return $this->quoteId;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'price' => $this->price,
            'currency' => $this->currencyCode,
            'quote_id' => $this->quoteId,
        ];
    }
}
