<?php

namespace Lilleprinsen\Cargonizer\API;

use Lilleprinsen\Cargonizer\Domain\Contracts\RateProviderInterface;
use Lilleprinsen\Cargonizer\Domain\DTO\RateQuote;
use Lilleprinsen\Cargonizer\Domain\DTO\RateQuoteRequest;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;

final class CargonizerClient implements RateProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://api.cargonizer.no';
    private const DEFAULT_TIMEOUT_SECONDS = 4;
    private const HTTP_MAX_RETRIES = 2;
    private const CIRCUIT_BREAKER_FAILURE_THRESHOLD = 3;
    private const CIRCUIT_BREAKER_TTL = 120;
    private const LOG_SOURCE = 'lp-cargonizer';

    private SettingsService $settings;

    /** @var array<string,mixed> */
    private array $requestMemo = [];

    /** @var \WC_Logger|null */
    private $logger = null;

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

        $response = $this->requestWithRetry('GET', 'transport_agreements', [], wp_generate_uuid4());
        if (is_wp_error($response)) {
            return [];
        }

        $normalized = $this->normalizeXmlResponse($response);
        if ($normalized === null) {
            return [];
        }

        $this->setCachedPayload('lp_carg_transport_agreements', $normalized, 10 * MINUTE_IN_SECONDS);

        return $this->requestMemo[$cacheKey] = $normalized;
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

        $response = $this->requestWithRetry('GET', 'service_partners', [], wp_generate_uuid4());
        if (is_wp_error($response)) {
            return [];
        }

        $normalized = $this->normalizeXmlResponse($response);
        if ($normalized === null) {
            return [];
        }

        $this->setCachedPayload('lp_carg_service_partners', $normalized, 10 * MINUTE_IN_SECONDS);

        return $this->requestMemo[$cacheKey] = $normalized;
    }


    public function getRateQuote(RateQuoteRequest $request): ?RateQuote
    {
        $payload = $request->toArray();
        $memoKey = 'quote:' . md5(wp_json_encode($payload));
        if (isset($this->requestMemo[$memoKey]) && is_array($this->requestMemo[$memoKey])) {
            $cachedQuote = $this->requestMemo[$memoKey];

            return isset($cachedQuote['price']) && is_numeric($cachedQuote['price'])
                ? new RateQuote((float) $cachedQuote['price'], (string) ($cachedQuote['currency'] ?? 'NOK'))
                : null;
        }

        $quoteCacheKey = 'lp_carg_quote_' . md5(wp_json_encode($payload));
        $cached = $this->getCachedPayload($quoteCacheKey);
        if (is_array($cached)) {
            $this->requestMemo[$memoKey] = $cached;

            return isset($cached['price']) && is_numeric($cached['price'])
                ? new RateQuote((float) $cached['price'], (string) ($cached['currency'] ?? 'NOK'))
                : null;
        }

        $correlationId = wp_generate_uuid4();
        do_action('lp_cargonizer_before_remote_quote_fetch', $payload, $correlationId, $this);

        $response = $this->requestWithRetry(
            'POST',
            'rate_quote',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ],
            $correlationId
        );

        if (is_wp_error($response)) {
            do_action('lp_cargonizer_after_remote_quote_fetch', null, $payload, $correlationId, $this, $response);

            return null;
        }

        $normalized = $this->normalizeQuoteResponse($response, $correlationId);
        if ($normalized === null) {
            do_action('lp_cargonizer_after_remote_quote_fetch', null, $payload, $correlationId, $this, null);

            return null;
        }

        $this->setCachedPayload($quoteCacheKey, $normalized, 5 * MINUTE_IN_SECONDS);
        $this->requestMemo[$memoKey] = $normalized;

        $quote = isset($normalized['price']) && is_numeric($normalized['price'])
            ? new RateQuote((float) $normalized['price'], (string) ($normalized['currency'] ?? 'NOK'))
            : null;

        do_action('lp_cargonizer_after_remote_quote_fetch', $quote, $payload, $correlationId, $this, null);

        return $quote;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    public function fetchRateQuote(array $payload): ?array
    {
        $request = new RateQuoteRequest(
            (string) ($payload['agreement_id'] ?? ''),
            (string) ($payload['product_id'] ?? ''),
            is_array($payload['package'] ?? null) ? $payload['package'] : []
        );

        $quote = $this->getRateQuote($request);

        return $quote !== null ? $quote->toArray() : null;
    }

    /**
     * @param array<string,mixed> $recipient
     * @param array<int,array<string,mixed>> $packages
     * @param array<string,mixed> $methodConfig
     * @return array<string,mixed>
     */
    public function estimateConsignmentCost(array $recipient, array $packages, array $methodConfig, array $estimateOptions = []): array
    {
        $correlationId = wp_generate_uuid4();
        $xmlBody = $this->buildConsignmentCostEstimateXml($recipient, $packages, $methodConfig, $estimateOptions);
        $response = $this->requestWithRetry(
            'POST',
            'consignment_costs',
            [
                'headers' => [
                    'Accept' => 'application/xml',
                    'Content-Type' => 'application/xml',
                ],
                'body' => $xmlBody,
            ],
            $correlationId
        );

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'method_id' => sanitize_text_field((string) ($methodConfig['method_id'] ?? '')),
                'correlation_id' => $correlationId,
                'http_status' => 0,
                'request_xml' => trim((string) $this->maskSecrets($xmlBody)),
                'raw_xml' => '',
                'errors' => [['message' => $response->get_error_message()]],
                'prices' => [],
            ];
        }

        $httpStatus = (int) wp_remote_retrieve_response_code($response);
        $rawXml = wp_remote_retrieve_body($response);
        $parsed = $this->parseConsignmentCostEstimateXml(is_string($rawXml) ? $rawXml : '');

        return [
            'ok' => $httpStatus >= 200 && $httpStatus < 300 && $parsed['errors'] === [],
            'method_id' => sanitize_text_field((string) ($methodConfig['method_id'] ?? '')),
            'correlation_id' => $correlationId,
            'http_status' => $httpStatus,
            'request_xml' => trim((string) $this->maskSecrets($xmlBody)),
            'raw_xml' => is_string($rawXml) ? trim((string) $this->maskSecrets($rawXml)) : '',
            'errors' => $parsed['errors'],
            'prices' => $parsed['prices'],
            'requirements' => $parsed['requirements'],
            'debug' => [
                'selected_servicepartner' => sanitize_text_field((string) ($estimateOptions['service_partner'] ?? '')),
                'selected_sms_service_id' => sanitize_text_field((string) ($estimateOptions['sms_service_id'] ?? '')),
                'custom_params' => isset($estimateOptions['custom_params']) && is_array($estimateOptions['custom_params']) ? $estimateOptions['custom_params'] : [],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function testConnection(): array
    {
        $correlationId = wp_generate_uuid4();
        $response = $this->requestWithRetry('GET', 'transport_agreements', [], $correlationId);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'message' => $response->get_error_message(),
                'correlation_id' => $correlationId,
                'status' => 0,
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $rawXml = wp_remote_retrieve_body($response);

        $errorMessage = $this->extractFirstXmlErrorMessage(is_string($rawXml) ? $rawXml : '');

        return [
            'ok' => $status >= 200 && $status < 300,
            'message' => $status >= 200 && $status < 300
                ? 'Connection successful.'
                : ($status === 401
                    ? 'Connection failed: Authentication rejected. Verify API key and sender/user relation ID from Cargonizer Preferences.'
                    : ($errorMessage !== '' ? sprintf('Connection failed: %s', $errorMessage) : 'Connection failed.')),
            'correlation_id' => $correlationId,
            'status' => $status,
            'raw_xml' => is_string($rawXml) ? trim((string) $this->maskSecrets($rawXml)) : '',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getDiagnostics(): array
    {
        return [
            'last_error' => get_option('lp_carg_last_error', []),
            'cache' => [
                'transport_agreements' => $this->cacheStatus('lp_carg_transport_agreements'),
                'service_partners' => $this->cacheStatus('lp_carg_service_partners'),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    private function requestWithRetry(string $method, string $endpointKey, array $args, string $correlationId)
    {
        $endpoint = $this->resolveEndpoint($endpointKey);
        if ($endpoint === null) {
            return new \WP_Error('lp_cargonizer_endpoint_missing', 'Cargonizer endpoint is not configured.');
        }

        $authHeaders = $this->buildAuthHeaders();
        if ($authHeaders === null) {
            return new \WP_Error('lp_cargonizer_auth_missing', 'Missing API credentials.');
        }

        $method = strtoupper($method);
        $httpArgs = array_merge(
            [
                'timeout' => $this->resolveTimeout(),
                'redirection' => 1,
                'headers' => [],
            ],
            $args
        );
        $httpArgs['headers'] = array_merge($authHeaders, is_array($httpArgs['headers']) ? $httpArgs['headers'] : []);

        if ($this->isCircuitOpen($endpoint)) {
            return new \WP_Error('lp_cargonizer_circuit_open', 'Endpoint temporarily disabled by circuit breaker.');
        }

        $attempts = self::HTTP_MAX_RETRIES + 1;
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $method === 'POST'
                ? wp_remote_post($endpoint, $httpArgs)
                : wp_remote_get($endpoint, $httpArgs);

            if (!$this->isTransientFailure($response)) {
                if (!is_wp_error($response)) {
                    $this->resetCircuit($endpoint);
                }

                $this->log('info', 'Request completed', [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'status' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
                    'attempt' => $attempt,
                    'correlation_id' => $correlationId,
                ]);

                return $response;
            }

            $this->registerFailure($endpoint);
            $this->recordLastError($response, $endpoint, $correlationId, $attempt);

            $this->log('warning', 'Transient failure while calling Cargonizer endpoint', [
                'endpoint' => $endpoint,
                'method' => $method,
                'attempt' => $attempt,
                'correlation_id' => $correlationId,
                'response' => $this->maskSecrets($response),
            ]);

            if ($attempt < $attempts) {
                $delayMs = (int) (100 * (2 ** ($attempt - 1)) + wp_rand(10, 60));
                usleep($delayMs * 1000);
            }
        }

        return is_wp_error($response)
            ? $response
            : new \WP_Error('lp_cargonizer_http_failed', 'Cargonizer request failed after retries.');
    }

    private function resolveEndpoint(string $endpointKey): ?string
    {
        $baseUrl = (string) apply_filters('lp_cargonizer_api_base_url', self::DEFAULT_BASE_URL);
        $settings = $this->settings->getSettings();

        $map = [
            'transport_agreements' => rtrim($baseUrl, '/') . '/transport_agreements.xml',
            'service_partners' => rtrim($baseUrl, '/') . '/service_partners.xml',
            'rate_quote' => (string) ($settings['rate_api_url'] ?? ''),
            'consignment_costs' => rtrim($baseUrl, '/') . '/consignment_costs.xml',
        ];

        $resolved = (string) ($map[$endpointKey] ?? '');

        return $resolved !== '' ? $resolved : null;
    }

    private function resolveTimeout(): int
    {
        $timeout = (int) apply_filters('lp_cargonizer_api_timeout', self::DEFAULT_TIMEOUT_SECONDS);

        return max(1, min(30, $timeout));
    }

    private function extractFirstXmlErrorMessage(string $rawXml): string
    {
        if ($rawXml === '' || !function_exists('simplexml_load_string')) {
            return '';
        }

        $xml = @simplexml_load_string($rawXml);
        if (!$xml instanceof \SimpleXMLElement) {
            return '';
        }

        if (isset($xml->error)) {
            $error = trim((string) $xml->error);
            if ($error !== '') {
                return $error;
            }
        }

        if (isset($xml->errors->error)) {
            foreach ($xml->errors->error as $errorNode) {
                if (!$errorNode instanceof \SimpleXMLElement) {
                    continue;
                }

                $error = trim((string) $errorNode);
                if ($error !== '') {
                    return $error;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string,string>|null
     */
    private function buildAuthHeaders(): ?array
    {
        $apiKey = $this->settings->getApiKey();
        $senderId = $this->settings->getSenderId();
        if ($apiKey === '' || $senderId === '') {
            return null;
        }

        return [
            'X-Cargonizer-Key' => $apiKey,
            'X-Cargonizer-Sender' => $senderId,
        ];
    }

    /**
     * @param array<string,mixed>|\WP_Error $response
     */
    private function isTransientFailure($response): bool
    {
        if (is_wp_error($response)) {
            return true;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        return in_array($status, [408, 425, 429, 500, 502, 503, 504], true);
    }

    /**
     * @param array<string,mixed>|\WP_Error $response
     */
    private function recordLastError($response, string $endpoint, string $correlationId, int $attempt): void
    {
        $message = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code($response);

        update_option('lp_carg_last_error', [
            'time' => gmdate('c'),
            'endpoint' => $endpoint,
            'message' => $this->maskSecrets($message),
            'attempt' => $attempt,
            'correlation_id' => $correlationId,
        ], false);
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>|null
     */
    private function normalizeXmlResponse(array $response): ?array
    {
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300 || !is_string($body) || trim($body) === '') {
            return null;
        }

        return [
            'status' => $status,
            'raw' => trim($body),
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>|null
     */
    private function normalizeQuoteResponse(array $response, string $correlationId): ?array
    {
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $this->recordLastError($response, 'rate_quote', $correlationId, 1);

            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        $price = $decoded['price'] ?? null;
        if (!is_numeric($price)) {
            return null;
        }

        return [
            'price' => (float) $price,
            'currency' => sanitize_text_field((string) ($decoded['currency'] ?? 'NOK')),
            'delivery_estimate' => sanitize_text_field((string) ($decoded['delivery_estimate'] ?? '')),
            'raw' => $decoded,
        ];
    }

    /**
     * @param array<string,mixed> $recipient
     * @param array<int,array<string,mixed>> $packages
     * @param array<string,mixed> $methodConfig
     */
    private function buildConsignmentCostEstimateXml(array $recipient, array $packages, array $methodConfig, array $estimateOptions = []): string
    {
        $agreementId = sanitize_text_field((string) ($methodConfig['agreement_id'] ?? ''));
        $productId = sanitize_text_field((string) ($methodConfig['product_id'] ?? ''));
        $servicePartner = sanitize_text_field((string) ($estimateOptions['service_partner'] ?? ''));

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<consignments>';
        $xml[] = '<consignment transport_agreement="' . $this->xmlEscape($agreementId) . '">';
        $xml[] = '<product>' . $this->xmlEscape($productId) . '</product>';
        $xml[] = '<parts>';
        $xml[] = '<consignee>';
        $xml[] = '<name>' . $this->xmlEscape((string) ($recipient['name'] ?? '')) . '</name>';
        $xml[] = '<address1>' . $this->xmlEscape((string) ($recipient['address1'] ?? '')) . '</address1>';
        $xml[] = '<address2>' . $this->xmlEscape((string) ($recipient['address2'] ?? '')) . '</address2>';
        $xml[] = '<postcode>' . $this->xmlEscape((string) ($recipient['postcode'] ?? '')) . '</postcode>';
        $xml[] = '<city>' . $this->xmlEscape((string) ($recipient['city'] ?? '')) . '</city>';
        $xml[] = '<country>' . $this->xmlEscape((string) ($recipient['country'] ?? '')) . '</country>';
        $xml[] = '</consignee>';

        if ($servicePartner !== '') {
            $xml[] = '<service_partner><number>' . $this->xmlEscape($servicePartner) . '</number></service_partner>';
        }

        $xml[] = '</parts>';

        $services = isset($estimateOptions['services']) && is_array($estimateOptions['services']) ? $estimateOptions['services'] : [];
        if ($services !== []) {
            $xml[] = '<services>';
            foreach ($services as $service) {
                if (!is_array($service)) {
                    continue;
                }

                $serviceId = sanitize_text_field((string) ($service['id'] ?? ''));
                if ($serviceId === '') {
                    continue;
                }

                $xml[] = '<service>';
                $xml[] = '<id>' . $this->xmlEscape($serviceId) . '</id>';
                if (isset($service['value']) && (string) $service['value'] !== '') {
                    $xml[] = '<value>' . $this->xmlEscape((string) $service['value']) . '</value>';
                }
                $xml[] = '</service>';
            }
            $xml[] = '</services>';
        }

        $xml[] = '<items>';
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $weight = max(0.0, (float) ($package['weight'] ?? 0));
            $length = max(0.0, (float) ($package['length'] ?? 0));
            $width = max(0.0, (float) ($package['width'] ?? 0));
            $height = max(0.0, (float) ($package['height'] ?? 0));
            $volumeDm3 = ($length * $width * $height) / 1000;
            $description = sanitize_text_field((string) ($package['description'] ?? ''));

            $xml[] = '<item type="package" amount="1" weight="' . $this->xmlEscape((string) $weight) . '" volume="' . $this->xmlEscape((string) $volumeDm3) . '" description="' . $this->xmlEscape($description) . '"/>';
        }
        $xml[] = '</items>';

        $customParams = isset($estimateOptions['custom_params']) && is_array($estimateOptions['custom_params']) ? $estimateOptions['custom_params'] : [];
        if ($customParams !== []) {
            $xml[] = '<customs>';
            foreach ($customParams as $customName => $customValue) {
                $name = sanitize_text_field((string) $customName);
                $value = sanitize_text_field((string) $customValue);
                if ($name === '' || $value === '') {
                    continue;
                }

                $xml[] = '<custom>';
                $xml[] = '<name>' . $this->xmlEscape($name) . '</name>';
                $xml[] = '<value>' . $this->xmlEscape($value) . '</value>';
                $xml[] = '</custom>';
            }
            $xml[] = '</customs>';
        }

        $xml[] = '</consignment>';
        $xml[] = '</consignments>';

        return implode('', $xml);
    }

    /**
     * @return array{errors:array<int,array<string,string>>,prices:array<string,float|null>,requirements:array<string,mixed>}
     */
    private function parseConsignmentCostEstimateXml(string $xml): array
    {
        if (!function_exists('simplexml_load_string')) {
            return [
                'errors' => [['message' => 'XML parser is unavailable on this host.']],
                'prices' => [],
                'requirements' => [],
            ];
        }

        $trimmed = trim($xml);
        if ($trimmed === '') {
            return [
                'errors' => [['message' => 'Empty XML response from Cargonizer.']],
                'prices' => [],
                'requirements' => [],
            ];
        }

        $document = @simplexml_load_string($trimmed);
        if ($document === false) {
            return [
                'errors' => [['message' => 'Could not parse XML response from Cargonizer.']],
                'prices' => [],
                'requirements' => [],
            ];
        }

        $errors = [];
        $errorNodes = $document->xpath('//error');
        if (is_array($errorNodes)) {
            foreach ($errorNodes as $errorNode) {
                if (!$errorNode instanceof \SimpleXMLElement) {
                    continue;
                }

                $errors[] = [
                    'code' => sanitize_text_field((string) ($errorNode->code ?? '')),
                    'field' => sanitize_text_field((string) ($errorNode->field ?? '')),
                    'message' => sanitize_text_field((string) ($errorNode->message ?? (string) $errorNode)),
                ];
            }
        }

        $requirementFlags = [
            'servicepartner_required' => false,
            'sms_required' => false,
        ];

        foreach ($errors as $error) {
            $haystack = strtolower(trim((string) (($error['field'] ?? '') . ' ' . ($error['message'] ?? '') . ' ' . ($error['code'] ?? ''))));
            if ($haystack === '') {
                continue;
            }

            if (strpos($haystack, 'servicepartner') !== false || strpos($haystack, 'service partner') !== false || strpos($haystack, 'service_partner') !== false) {
                $requirementFlags['servicepartner_required'] = true;
            }

            if (strpos($haystack, 'sms') !== false) {
                $requirementFlags['sms_required'] = true;
            }
        }

        return [
            'errors' => $errors,
            'prices' => [
                'estimated_cost' => $this->xmlNodeFloat($document, ['//estimated_cost', '//estimated-cost']),
                'gross_amount' => $this->xmlNodeFloat($document, ['//gross_amount', '//gross-amount']),
                'net_amount' => $this->xmlNodeFloat($document, ['//net_amount', '//net-amount']),
                'price' => $this->xmlNodeFloat($document, ['//price', '//amount']),
                'total' => $this->xmlNodeFloat($document, ['//total', '//total_amount']),
            ],
            'requirements' => $requirementFlags,
        ];
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<int,string> $paths
     */
    private function xmlNodeFloat(\SimpleXMLElement $document, array $paths): ?float
    {
        foreach ($paths as $path) {
            $nodes = $document->xpath($path);
            if (!is_array($nodes) || !isset($nodes[0])) {
                continue;
            }

            $raw = trim((string) $nodes[0]);
            if ($raw === '') {
                continue;
            }

            $normalized = str_replace(',', '.', $raw);
            if (is_numeric($normalized)) {
                return (float) $normalized;
            }
        }

        return null;
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

    private function cacheStatus(string $key): array
    {
        $cached = get_transient($key);

        return [
            'present' => is_array($cached),
            'size' => is_array($cached) ? strlen((string) wp_json_encode($cached)) : 0,
        ];
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

    /**
     * @param mixed $payload
     * @return mixed
     */
    private function maskSecrets($payload)
    {
        if (is_array($payload)) {
            $masked = [];
            foreach ($payload as $key => $value) {
                $lower = strtolower((string) $key);
                if (strpos($lower, 'key') !== false || strpos($lower, 'token') !== false || strpos($lower, 'secret') !== false || strpos($lower, 'sender') !== false) {
                    $masked[$key] = '***';
                    continue;
                }

                $masked[$key] = $this->maskSecrets($value);
            }

            return $masked;
        }

        if (is_string($payload)) {
            $apiKey = $this->settings->getApiKey();
            $sender = $this->settings->getSenderId();
            if ($apiKey !== '') {
                $payload = str_replace($apiKey, '***', $payload);
            }

            if ($sender !== '') {
                $payload = str_replace($sender, '***', $payload);
            }
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        if ($this->logger === null) {
            $this->logger = wc_get_logger();
        }

        $context['source'] = self::LOG_SOURCE;
        $context = $this->maskSecrets($context);

        $this->logger->log($level, $message, $context);
    }
}
