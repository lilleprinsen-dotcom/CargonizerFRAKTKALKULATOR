<?php

namespace Lilleprinsen\Cargonizer\Shipping;

use Lilleprinsen\Cargonizer\Infrastructure\CargonizerClient;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;
use Lilleprinsen\Cargonizer\Shipping\Methods\CargonizerShippingMethod;

final class ShippingMethodRegistry
{
    public const ACTION_REFRESH_METHODS = 'lp_cargonizer_refresh_methods';
    public const ACTION_RUN_BULK_ESTIMATE = 'lp_cargonizer_bulk_estimate';

    private SettingsService $settings;
    private CargonizerClient $client;
    private RateCalculator $rateCalculator;

    /** @var array<string,float|null> */
    private array $requestRateMemo = [];

    public function __construct(SettingsService $settings, CargonizerClient $client, RateCalculator $rateCalculator)
    {
        $this->settings = $settings;
        $this->client = $client;
        $this->rateCalculator = $rateCalculator;
    }

    public function all(): array
    {
        $settings = $this->settings->getSettings();

        return isset($settings['available_methods']) && is_array($settings['available_methods'])
            ? array_values($settings['available_methods'])
            : [];
    }

    public function getMethodConfigByInstanceId(int $instanceId): array
    {
        foreach ($this->all() as $method) {
            if ((int) ($method['instance_id'] ?? 0) === $instanceId) {
                return is_array($method) ? $method : [];
            }
        }

        return [];
    }

    public function getMethodClassMap(): array
    {
        $map = [];

        foreach ($this->all() as $method) {
            if (!is_array($method)) {
                continue;
            }

            $methodId = (string) ($method['method_id'] ?? '');
            if ($methodId === '') {
                continue;
            }

            $map[$methodId] = CargonizerShippingMethod::class;
        }

        return $map;
    }

    public function refreshFromCargonizer(): array
    {
        $payload = $this->client->fetchTransportAgreements();
        if (!isset($payload['raw']) || !is_string($payload['raw'])) {
            return [];
        }

        $methods = $this->extractMethodsFromXml($payload['raw']);
        if ($methods === []) {
            return [];
        }

        $current = $this->settings->getSettings();
        $current['available_methods'] = $methods;
        $this->settings->save($current);

        return $methods;
    }

    public function refreshFromCargonizerAsync(): bool
    {
        if (!function_exists('as_enqueue_async_action')) {
            return false;
        }

        as_enqueue_async_action(self::ACTION_REFRESH_METHODS, [], 'lp-cargonizer');

        return true;
    }

    public function runRefreshMethodsJob(): void
    {
        $this->refreshFromCargonizer();
    }

    /**
     * @param array<int,array<string,mixed>> $jobs
     */
    public function enqueueBulkEstimate(array $jobs): ?string
    {
        if (!function_exists('as_enqueue_async_action')) {
            return null;
        }

        $jobId = wp_generate_uuid4();
        as_enqueue_async_action(self::ACTION_RUN_BULK_ESTIMATE, [
            'job_id' => $jobId,
            'jobs' => $jobs,
        ], 'lp-cargonizer');

        return $jobId;
    }

    /**
     * @param array<string,mixed> $args
     */
    public function runBulkEstimateJob(array $args): void
    {
        $jobId = sanitize_text_field((string) ($args['job_id'] ?? ''));
        $jobs = isset($args['jobs']) && is_array($args['jobs']) ? $args['jobs'] : [];
        if ($jobId === '' || $jobs === []) {
            return;
        }

        $results = [];
        foreach ($jobs as $item) {
            if (!is_array($item)) {
                continue;
            }

            $methodConfig = isset($item['method']) && is_array($item['method']) ? $item['method'] : [];
            $package = isset($item['package']) && is_array($item['package']) ? $item['package'] : [];
            $results[] = [
                'method_id' => (string) ($methodConfig['method_id'] ?? ''),
                'rate' => $this->resolveRate($methodConfig, $package),
            ];
        }

        set_transient('lp_carg_bulk_' . $jobId, $results, 15 * MINUTE_IN_SECONDS);
    }

    /**
     * @param array<string,mixed> $methodConfig
     * @param array<string,mixed> $package
     */
    public function resolveRate(array $methodConfig, array $package): ?float
    {
        $cacheKey = $this->buildRateCacheKey($methodConfig, $package);
        if (array_key_exists($cacheKey, $this->requestRateMemo)) {
            return $this->requestRateMemo[$cacheKey];
        }

        $cached = get_transient($cacheKey);
        if (is_numeric($cached)) {
            $validated = $this->validateRate((float) $cached);
            $this->requestRateMemo[$cacheKey] = $validated;

            return $validated;
        }

        $quote = $this->client->fetchRateQuote([
            'agreement_id' => (string) ($methodConfig['agreement_id'] ?? ''),
            'product_id' => (string) ($methodConfig['product_id'] ?? ''),
            'package' => $this->compactPackage($package),
        ]);

        $rawRate = is_array($quote) && isset($quote['price']) && is_numeric($quote['price'])
            ? (float) $quote['price']
            : null;

        if ($rawRate === null) {
            $rawRate = $this->getFallbackRate($methodConfig);
        }

        if ($rawRate === null) {
            $this->requestRateMemo[$cacheKey] = null;

            return null;
        }

        $calculated = $this->rateCalculator->calculate($rawRate, $this->settings->getPricingModifiers());
        $validated = $this->validateRate($calculated);

        if ($validated === null) {
            $this->requestRateMemo[$cacheKey] = null;

            return null;
        }

        set_transient($cacheKey, $validated, 10 * MINUTE_IN_SECONDS);
        $this->requestRateMemo[$cacheKey] = $validated;

        return $validated;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractMethodsFromXml(string $xml): array
    {
        if (!function_exists('simplexml_load_string')) {
            return [];
        }

        $document = @simplexml_load_string($xml);
        if ($document === false) {
            return [];
        }

        $methods = [];
        $instance = 1;

        $agreements = $document->xpath('//transport_agreement');
        if (!is_array($agreements)) {
            return [];
        }

        foreach ($agreements as $agreement) {
            $agreementId = trim((string) ($agreement->id ?? ''));
            $agreementName = trim((string) ($agreement->name ?? ''));
            $products = $agreement->xpath('.//product');
            if (!is_array($products)) {
                continue;
            }

            foreach ($products as $product) {
                $productId = trim((string) ($product->id ?? ''));
                $productName = trim((string) ($product->name ?? ''));

                if ($agreementId === '' || $productId === '') {
                    continue;
                }

                $methodId = sprintf('lp_cargonizer_%s_%s', sanitize_key($agreementId), sanitize_key($productId));
                $title = trim($agreementName . ' - ' . $productName);
                if ($title === '') {
                    $title = sprintf('Cargonizer %s/%s', $agreementId, $productId);
                }

                $methods[] = [
                    'instance_id' => $instance,
                    'method_id' => $methodId,
                    'agreement_id' => $agreementId,
                    'agreement_name' => $agreementName,
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'title' => $title,
                    'enabled' => 'yes',
                    'fallback_rate' => 0,
                ];

                $instance++;
            }
        }

        return $methods;
    }

    /**
     * @param array<string,mixed> $methodConfig
     */
    private function getFallbackRate(array $methodConfig): ?float
    {
        if (isset($methodConfig['fallback_rate']) && is_numeric($methodConfig['fallback_rate'])) {
            return (float) $methodConfig['fallback_rate'];
        }

        $fallbackRates = $this->settings->getStaticFallbackRates();
        $key = (string) ($methodConfig['method_id'] ?? '');

        if ($key !== '' && isset($fallbackRates[$key]) && is_numeric($fallbackRates[$key])) {
            return (float) $fallbackRates[$key];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $methodConfig
     * @param array<string,mixed> $package
     */
    private function buildRateCacheKey(array $methodConfig, array $package): string
    {
        $parts = [
            'method_id' => (string) ($methodConfig['method_id'] ?? ''),
            'agreement_id' => (string) ($methodConfig['agreement_id'] ?? ''),
            'product_id' => (string) ($methodConfig['product_id'] ?? ''),
            'package' => $this->compactPackage($package),
        ];

        return 'lp_carg_rate_' . md5(wp_json_encode($parts));
    }

    /**
     * @param array<string,mixed> $package
     * @return array<string,mixed>
     */
    private function compactPackage(array $package): array
    {
        $destination = is_array($package['destination'] ?? null) ? $package['destination'] : [];
        $contents = is_array($package['contents'] ?? null) ? $package['contents'] : [];

        return [
            'destination' => [
                'country' => sanitize_text_field((string) ($destination['country'] ?? '')),
                'state' => sanitize_text_field((string) ($destination['state'] ?? '')),
                'postcode' => sanitize_text_field((string) ($destination['postcode'] ?? '')),
                'city' => sanitize_text_field((string) ($destination['city'] ?? '')),
            ],
            'contents' => array_values(array_map(static function ($item): array {
                if (!is_array($item)) {
                    return [];
                }

                $product = isset($item['data']) && is_object($item['data']) ? $item['data'] : null;

                return [
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'variation_id' => (int) ($item['variation_id'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'weight' => $product !== null && method_exists($product, 'get_weight') ? (float) $product->get_weight() : 0.0,
                    'length' => $product !== null && method_exists($product, 'get_length') ? (float) $product->get_length() : 0.0,
                    'width' => $product !== null && method_exists($product, 'get_width') ? (float) $product->get_width() : 0.0,
                    'height' => $product !== null && method_exists($product, 'get_height') ? (float) $product->get_height() : 0.0,
                ];
            }, $contents)),
        ];
    }

    private function validateRate(float $rate): ?float
    {
        if (!is_finite($rate) || $rate < 0) {
            return null;
        }

        return round($rate, 2);
    }
}
