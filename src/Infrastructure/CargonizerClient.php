<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

final class CargonizerClient
{
    private SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    public function fetchTransportAgreements(): array
    {
        $apiKey = $this->settings->getApiKey();
        $senderId = $this->settings->getSenderId();

        if ($apiKey === '' || $senderId === '') {
            return [];
        }

        $response = wp_remote_get(
            'https://api.cargonizer.no/transport_agreements.xml',
            [
                'headers' => [
                    'X-Cargonizer-Key' => $apiKey,
                    'X-Cargonizer-Sender' => $senderId,
                ],
                'timeout' => 20,
            ]
        );

        if (is_wp_error($response)) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || trim($body) === '') {
            return [];
        }

        return [
            'raw' => $body,
            'status' => (int) wp_remote_retrieve_response_code($response),
        ];
    }
}
