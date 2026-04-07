<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

final class CargonizerClient
{
    private const HTTP_TIMEOUT_SECONDS = 4;
    private const HTTP_RETRIES = 1;
    private const CIRCUIT_BREAKER_FAILURE_THRESHOLD = 3;
    private const CIRCUIT_BREAKER_TTL = 120;

    private SettingsService $settings;

    /** @var array<string,mixed> */
    private array $requestMemo = [];

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    public function fetchTransportAgreements(): array
    {
        $cacheKey = 'transport_agreements';
        $memoized = $this->requestMemo[$cacheKey] ?? null;
        if (is_array($memoized)) {
            return $memoized;
        }

        $cached = $this->getCachedPayload('lp_carg_transport_agreements');
        if (is_array($cached)) {
            return $this->requestMemo[$cacheKey] = $cached;
        }

        $apiKey = $this->settings->getApiKey();
        $senderId = $this->settings->getSenderId();

        if ($apiKey === '' || $senderId === '') {
            return [];
        }

        $endpoint = 'https://api.cargonizer.no/transport_agreements.xml';
        $response = $this->requestWithRetry(
            'GET',
            $endpoint,
            [
                'headers' => [
                    'X-Cargonizer-Key' => $apiKey,
                    'X-Cargonizer-Sender' => $senderId,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || trim($body) === '') {
            return [];
        }

        $payload = [
            'raw' => $body,
            'status' => (int) wp_remote_retrieve_response_code($response),
        ];

        $this->setCachedPayload('lp_carg_transport_agreements', $payload, 10 * MINUTE_IN_SECONDS);

        return $this->requestMemo[$cacheKey] = $payload;
    }

    public function fetchServicePartners(): array
    {
        $cacheKey = 'service_partners';
        $memoized = $this->requestMemo[$cacheKey] ?? null;
        if (is_array($memoized)) {
            return $memoized;
        }

        $cached = $this->getCachedPayload('lp_carg_service_partners');
        if (is_array($cached)) {
            return $this->requestMemo[$cacheKey] = $cached;
        }

        $apiKey = $this->settings->getApiKey();
        $senderId = $this->settings->getSenderId();

        if ($apiKey === '' || $senderId === '') {
            return [];
        }

        $endpoint = 'https://api.cargonizer.no/service_partners.xml';
        $response = $this->requestWithRetry(
            'GET',
            $endpoint,
            [
                'headers' => [
                    'X-Cargonizer-Key' => $apiKey,
                    'X-Cargonizer-Sender' => $senderId,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || trim($body) === '') {
            return [];
        }

        $payload = [
            'raw' => $body,
            'status' => (int) wp_remote_retrieve_response_code($response),
        ];

        $this->setCachedPayload('lp_carg_service_partners', $payload, 10 * MINUTE_IN_SECONDS);

        return $this->requestMemo[$cacheKey] = $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    public function fetchRateQuote(array $payload): ?array
    {
        $memoKey = 'quote:' . md5(wp_json_encode($payload));
        if (isset($this->requestMemo[$memoKey]) && is_array($this->requestMemo[$memoKey])) {
            return $this->requestMemo[$memoKey];
        }

        $quoteCacheKey = 'lp_carg_quote_' . md5(wp_json_encode($payload));
        $cached = $this->getCachedPayload($quoteCacheKey);
        if (is_array($cached)) {
            $this->requestMemo[$memoKey] = $cached;

            return $cached;
        }

        $endpoint = (string) ($this->settings->getSettings()['rate_api_url'] ?? '');
        if ($endpoint === '') {
            return apply_filters('lp_cargonizer_rate_quote_result', null, $payload);
        }

        $apiKey = $this->settings->getApiKey();
        $senderId = $this->settings->getSenderId();

        if ($apiKey === '' || $senderId === '') {
            return null;
        }

        $response = $this->requestWithRetry(
            'POST',
            $endpoint,
            [
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
        if (!is_array($decoded)) {
            return null;
        }

        $this->setCachedPayload($quoteCacheKey, $decoded, 5 * MINUTE_IN_SECONDS);
        $this->requestMemo[$memoKey] = $decoded;

        return $decoded;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    private function requestWithRetry(string $method, string $endpoint, array $args)
    {
        $method = strtoupper($method);
        $httpArgs = array_merge(
            [
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'redirection' => 1,
            ],
            $args
        );

        if ($this->isCircuitOpen($endpoint)) {
            return new \WP_Error('lp_cargonizer_circuit_open', 'Endpoint temporarily disabled by circuit breaker.');
        }

        $attempts = self::HTTP_RETRIES + 1;
        $response = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $method === 'POST'
                ? wp_remote_post($endpoint, $httpArgs)
                : wp_remote_get($endpoint, $httpArgs);

            if (!is_wp_error($response)) {
                $status = (int) wp_remote_retrieve_response_code($response);
                if ($status >= 200 && $status < 500) {
                    $this->resetCircuit($endpoint);

                    return $response;
                }
            }

            $this->registerFailure($endpoint);
            if ($attempt < $attempts) {
                usleep(150000);
            }
        }

        return is_wp_error($response)
            ? $response
            : new \WP_Error('lp_cargonizer_http_failed', 'Cargonizer request failed after retries.');
    }

    private function isCircuitOpen(string $endpoint): bool
    {
        $state = get_transient($this->circuitBreakerKey($endpoint));

        return is_array($state) && (($state['open_until'] ?? 0) > time());
    }

    private function registerFailure(string $endpoint): void
    {
        $key = $this->circuitBreakerKey($endpoint);
        $state = get_transient($key);
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        $count++;

        $payload = ['count' => $count];
        if ($count >= self::CIRCUIT_BREAKER_FAILURE_THRESHOLD) {
            $payload['open_until'] = time() + self::CIRCUIT_BREAKER_TTL;
        }

        set_transient($key, $payload, self::CIRCUIT_BREAKER_TTL);
    }

    private function resetCircuit(string $endpoint): void
    {
        delete_transient($this->circuitBreakerKey($endpoint));
    }

    private function circuitBreakerKey(string $endpoint): string
    {
        return 'lp_carg_cb_' . md5($endpoint);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getCachedPayload(string $key): ?array
    {
        $objectCache = wp_cache_get($key, 'lp_cargonizer');
        if (is_array($objectCache)) {
            return $objectCache;
        }

        $transient = get_transient($key);
        if (is_array($transient)) {
            wp_cache_set($key, $transient, 'lp_cargonizer', 60);

            return $transient;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function setCachedPayload(string $key, array $payload, int $ttl): void
    {
        wp_cache_set($key, $payload, 'lp_cargonizer', $ttl);
        set_transient($key, $payload, $ttl);
    }
}
