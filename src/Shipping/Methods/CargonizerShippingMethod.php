<?php

namespace Lilleprinsen\Cargonizer\Shipping\Methods;

use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;

class CargonizerShippingMethod extends \WC_Shipping_Method
{
    protected ShippingMethodRegistry $registry;

    public function __construct(int $instanceId = 0)
    {
        $this->id = 'lp_cargonizer';
        $this->instance_id = absint($instanceId);
        $this->method_title = __('Cargonizer', 'lp-cargonizer');
        $this->method_description = __('Dynamic shipping rates from Cargonizer agreements.', 'lp-cargonizer');
        $this->supports = ['shipping-zones', 'instance-settings'];

        $registry = lp_cargonizer_container()->get(ShippingMethodRegistry::class);
        $this->registry = $registry;

        $methodConfig = $this->registry->getMethodConfigByInstanceId($this->instance_id);

        $this->title = (string) ($methodConfig['title'] ?? __('Cargonizer shipping', 'lp-cargonizer'));
        $this->enabled = (string) ($methodConfig['enabled'] ?? 'yes');

        $this->init();
    }

    public function init(): void
    {
        $this->init_instance_settings();
        $this->init_form_fields();
        $this->init_settings();
    }

    public function init_form_fields(): void
    {
        $this->instance_form_fields = [
            'title' => [
                'title' => __('Title', 'lp-cargonizer'),
                'type' => 'text',
                'default' => $this->title,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $package
     */
    public function calculate_shipping($package = []): void
    {
        $methodConfig = $this->registry->getMethodConfigByInstanceId($this->instance_id);
        if ($methodConfig === []) {
            return;
        }

        $rate = $this->registry->resolveRate($methodConfig, is_array($package) ? $package : []);
        if ($rate === null) {
            return;
        }

        $rateDefinition = [
            'id' => $this->id . ':' . $this->instance_id,
            'label' => (string) ($this->get_option('title', $methodConfig['title'] ?? $this->title)),
            'cost' => $rate,
            'package' => $package,
        ];

        /** @var array<string,mixed> $rateDefinition */
        $rateDefinition = apply_filters('lp_cargonizer_before_rate_publish', $rateDefinition, $methodConfig, $package, $this);

        do_action('lp_cargonizer_before_rate_publish_action', $rateDefinition, $methodConfig, $package, $this);

        $this->add_rate($rateDefinition);
    }
}
