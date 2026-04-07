<?php

namespace Lilleprinsen\Cargonizer\Domain\DTO;

final class PickupPointRequest
{
    private string $country;
    private string $postalCode;
    private ?string $city;

    public function __construct(string $country, string $postalCode, ?string $city = null)
    {
        $this->country = $country;
        $this->postalCode = $postalCode;
        $this->city = $city;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }
}
