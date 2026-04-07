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

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    public function fetchRateQuote(array $payload): ?array
    {
        $endpoint = (string) ($this->settings->getSettings()['rate_api_url'] ?? '');
        if ($endpoint === '') {
            return apply_filters('lp_cargonizer_rate_quote_result', null, $payload);
        }

        $apiKey = $this->settings->getApiKey();
        $senderId = $this->settings->getSenderId();

        if ($apiKey === '' || $senderId === '') {
            return null;
        }

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout' => 15,
                'headers' => [
                    'X-Cargonizer-Key' => $apiKey,
                    'X-Cargonizer-Sender' => $senderId,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
