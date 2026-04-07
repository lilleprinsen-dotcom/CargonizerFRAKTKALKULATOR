<?php

namespace Lilleprinsen\Cargonizer\Shipping;

use Lilleprinsen\Cargonizer\Infrastructure\CargonizerClient;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;

final class ShippingMethodRegistry
{
    private SettingsService $settings;
    private CargonizerClient $client;

    public function __construct(SettingsService $settings, CargonizerClient $client)
    {
        $this->settings = $settings;
        $this->client = $client;
    }

    public function all(): array
    {
        $settings = $this->settings->getSettings();

        return isset($settings['available_methods']) && is_array($settings['available_methods'])
            ? $settings['available_methods']
            : [];
    }

    public function refreshFromCargonizer(): array
    {
        return $this->client->fetchTransportAgreements();
    }
}
