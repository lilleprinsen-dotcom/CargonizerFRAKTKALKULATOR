<?php

namespace Lilleprinsen\Cargonizer\Shipping;

use Lilleprinsen\Cargonizer\Shipping\Methods\CargonizerShippingMethod;

final class WooShippingIntegration
{
    private ShippingMethodRegistry $registry;

    public function __construct(ShippingMethodRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function shippingInit(): void
    {
        if (!class_exists('WC_Shipping_Method')) {
            return;
        }

        // Autoloader resolves class, but forcing reference ensures class availability at hook runtime.
        class_exists(CargonizerShippingMethod::class);
    }

    /**
     * @param array<string,string> $methods
     * @return array<string,string>
     */
    public function registerMethods(array $methods): array
    {
        foreach ($this->registry->getMethodClassMap() as $methodId => $className) {
            $methods[$methodId] = $className;
        }

        return $methods;
    }
}
