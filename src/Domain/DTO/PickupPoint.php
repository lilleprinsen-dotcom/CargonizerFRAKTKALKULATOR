<?php

namespace Lilleprinsen\Cargonizer\Domain\DTO;

final class PickupPoint
{
    private string $id;
    private string $name;
    private string $address;
    private string $postalCode;
    private string $city;
    private string $country;

    public function __construct(
        string $id,
        string $name,
        string $address,
        string $postalCode,
        string $city,
        string $country
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->address = $address;
        $this->postalCode = $postalCode;
        $this->city = $city;
        $this->country = $country;
    }

    /**
     * @return array<string,string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'country' => $this->country,
        ];
    }
}
