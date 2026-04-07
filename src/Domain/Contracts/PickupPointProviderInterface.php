<?php

namespace Lilleprinsen\Cargonizer\Domain\Contracts;

use Lilleprinsen\Cargonizer\Domain\DTO\PickupPoint;
use Lilleprinsen\Cargonizer\Domain\DTO\PickupPointRequest;

interface PickupPointProviderInterface
{
    /**
     * @return array<int,PickupPoint>
     */
    public function getPickupPoints(PickupPointRequest $request): array;
}
