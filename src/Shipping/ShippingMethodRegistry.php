<?php

namespace Lilleprinsen\Cargonizer\Shipping;

use Lilleprinsen\Cargonizer\API\CargonizerClient;
use Lilleprinsen\Cargonizer\Domain\Contracts\RateProviderInterface;
use Lilleprinsen\Cargonizer\Domain\DTO\RateQuoteRequest;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;
use Lilleprinsen\Cargonizer\Shipping\Methods\CargonizerShippingMethod;

final class ShippingMethodRegistry
{
    public const ACTION_REFRESH_METHODS = 'lp_cargonizer_refresh_methods';
    public const ACTION_RUN_BULK_ESTIMATE = 'lp_cargonizer_bulk_estimate';

    private SettingsService $settings;
    private CargonizerClient $client;
    private RateProviderInterface $rateProvider;
    private RateCalculator $rateCalculator;

    /** @var array<string,float|null> */
    private array $requestRateMemo = [];

    public function __construct(SettingsService $settings, CargonizerClient $client, RateProviderInterface $rateProvider, RateCalculator $rateCalculator)
    {
        $this->settings = $settings;
        $this->client = $client;
        $this->rateProvider = $rateProvider;
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

    public function getMethodConfigByMethodId(string $methodId): array
    {
        $methodId = sanitize_key($methodId);
        if ($methodId === '') {
            return [];
        }

        foreach ($this->all() as $method) {
            if (!is_array($method)) {
                continue;
            }

            if (sanitize_key((string) ($method['method_id'] ?? '')) === $methodId) {
                return $method;
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

        $current = $this->settings->getSettings();
        $methods = $this->extractMethodsFromXml($payload['raw'], isset($current['available_methods']) && is_array($current['available_methods']) ? $current['available_methods'] : []);
        if ($methods === []) {
            return [];
        }

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

        $quoteRequest = new RateQuoteRequest(
            (string) ($methodConfig['agreement_id'] ?? ''),
            (string) ($methodConfig['product_id'] ?? ''),
            $this->compactPackage($package)
        );

        $quote = $this->rateProvider->getRateQuote($quoteRequest);

        $rawRate = $quote !== null ? $quote->getPrice() : null;

        if ($rawRate === null) {
            $rawRate = $this->getFallbackRate($methodConfig);
        }

        if ($rawRate === null) {
            $this->requestRateMemo[$cacheKey] = null;

            return null;
        }

        $calculated = $this->rateCalculator->calculate($rawRate, $this->settings->getPricingModifiers(), $quoteRequest);
        $calculated = (float) apply_filters('lp_cargonizer_rate_post_processing', $calculated, $rawRate, $methodConfig, $package, $quoteRequest, $quote);
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
     * @param array<string,mixed> $methodConfig
     * @param array<string,mixed> $package
     * @param array<string,mixed> $recipient
     * @return array<string,mixed>
     */
    public function resolveAdminEstimate(array $methodConfig, array $package, array $recipient): array
    {
        $methodId = (string) ($methodConfig['method_id'] ?? '');
        $fallbackRate = $this->getFallbackRate($methodConfig);
        $result = $this->client->estimateConsignmentCost(
            $recipient,
            $this->extractPackagesFromPackageInput($package),
            $methodConfig
        );

        $prices = is_array($result['prices'] ?? null) ? $result['prices'] : [];
        $baseRate = $this->pickAdminEstimateBaseRate($methodConfig, $prices, $fallbackRate);
        $quoteRequest = new RateQuoteRequest(
            (string) ($methodConfig['agreement_id'] ?? ''),
            (string) ($methodConfig['product_id'] ?? ''),
            $this->compactPackage($package)
        );

        $computedRate = $baseRate !== null
            ? $this->validateRate($this->rateCalculator->calculate($baseRate, $this->settings->getPricingModifiers(), $quoteRequest))
            : null;

        return [
            'method_id' => $methodId,
            'title' => (string) ($methodConfig['title'] ?? $methodId),
            'rate' => $computedRate,
            'price_source' => $this->getMethodPriceSource($methodConfig),
            'fallback_rate' => $fallbackRate,
            'estimate' => $result,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractMethodsFromXml(string $xml, array $existingMethods = []): array
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
            $agreementId = $this->xmlValue($agreement, ['agreement_id', 'id']);
            $agreementName = $this->xmlValue($agreement, ['agreement_name', 'name']);
            $agreementDescription = $this->xmlValue($agreement, ['agreement_description', 'description']);
            $agreementNumber = $this->xmlValue($agreement, ['agreement_number', 'number']);

            $carrierId = $this->xmlValue($agreement, [
                'carrier/carrier_id',
                'carrier/id',
                'carrier_id',
            ]);
            $carrierName = $this->xmlValue($agreement, [
                'carrier/carrier_name',
                'carrier/name',
                'carrier_name',
                'carrier',
            ]);

            $products = $agreement->xpath('./products/product');
            if (!is_array($products) || $products === []) {
                $products = $agreement->xpath('.//product');
            }
            if (!is_array($products)) {
                continue;
            }

            foreach ($products as $product) {
                $productId = $this->xmlValue($product, ['product_id', 'id']);
                $productName = $this->xmlValue($product, ['product_name', 'name']);

                if ($agreementId === '' || $productId === '') {
                    continue;
                }

                $methodKey = $agreementId . '|' . $productId;
                $methodId = sprintf('lp_cargonizer_%s', sanitize_key($methodKey));
                $title = trim($carrierName . ' - ' . $agreementName . ' - ' . $productName, ' -');
                if ($title === '') {
                    $title = sprintf('Cargonizer %s/%s', $agreementId, $productId);
                }
                $existing = $this->findMethodById($methodId, $existingMethods);
                $services = [];
                $serviceNodes = $product->xpath('./services/service');
                if (!is_array($serviceNodes) || $serviceNodes === []) {
                    $serviceNodes = $product->xpath('.//service');
                }
                if (is_array($serviceNodes)) {
                    foreach ($serviceNodes as $serviceNode) {
                        $serviceId = $this->xmlValue($serviceNode, ['service_id', 'id']);
                        $serviceName = $this->xmlValue($serviceNode, ['service_name', 'name']);
                        if ($serviceName !== '' || $serviceId !== '') {
                            $services[] = [
                                'service_id' => $serviceId,
                                'service_name' => $serviceName,
                            ];
                        }
                    }
                }

                $methods[] = [
                    'instance_id' => $instance,
                    'method_id' => $methodId,
                    'key' => $methodKey,
                    'carrier_name' => $carrierName,
                    'carrier_id' => $carrierId,
                    'agreement_id' => $agreementId,
                    'agreement_name' => $agreementName,
                    'agreement_description' => $agreementDescription,
                    'agreement_number' => $agreementNumber,
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'services' => $services,
                    'title' => $title,
                    'is_manual' => false,
                    'enabled' => (string) ($existing['enabled'] ?? 'yes') === 'no' ? 'no' : 'yes',
                    'fallback_rate' => isset($existing['fallback_rate']) && is_numeric($existing['fallback_rate']) ? (float) $existing['fallback_rate'] : 0,
                ];

                $instance++;
            }
        }

        $manualMethodId = sprintf('lp_cargonizer_%s', sanitize_key('manual|norgespakke'));
        $manualExisting = $this->findMethodById($manualMethodId, $existingMethods);
        $methods[] = [
            'instance_id' => $instance,
            'method_id' => $manualMethodId,
            'key' => 'manual|norgespakke',
            'carrier_name' => 'Posten',
            'carrier_id' => 'manual',
            'agreement_id' => 'manual',
            'agreement_name' => 'Manuell',
            'agreement_description' => 'Synthetic manual method',
            'agreement_number' => 'manual',
            'product_id' => 'norgespakke',
            'product_name' => 'Norgespakke',
            'services' => [],
            'title' => 'Posten - Manuell - Norgespakke',
            'is_manual' => true,
            'enabled' => (string) ($manualExisting['enabled'] ?? 'yes') === 'no' ? 'no' : 'yes',
            'fallback_rate' => isset($manualExisting['fallback_rate']) && is_numeric($manualExisting['fallback_rate']) ? (float) $manualExisting['fallback_rate'] : 0,
        ];

        return $methods;
    }

    private function xmlValue(\SimpleXMLElement $node, array $paths): string
    {
        foreach ($paths as $path) {
            $value = trim((string) ($node->{$path} ?? ''));
            if ($value !== '') {
                return $value;
            }

            $matches = $node->xpath('./' . $path);
            if (is_array($matches) && isset($matches[0])) {
                $matchValue = trim((string) $matches[0]);
                if ($matchValue !== '') {
                    return $matchValue;
                }
            }
        }

        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $methods
     * @return array<string,mixed>
     */
    private function findMethodById(string $methodId, array $methods): array
    {
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            if ((string) ($method['method_id'] ?? '') === $methodId) {
                return $method;
            }
        }

        return [];
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

    /**
     * @param array<string,mixed> $methodConfig
     */
    private function getMethodPriceSource(array $methodConfig): string
    {
        $settings = $this->settings->getSettings();
        $methodPricing = is_array($settings['method_pricing'] ?? null) ? $settings['method_pricing'] : [];
        $methodId = sanitize_key((string) ($methodConfig['method_id'] ?? ''));
        $configured = is_array($methodPricing[$methodId] ?? null) ? $methodPricing[$methodId] : [];

        return sanitize_key((string) ($configured['price_source'] ?? 'estimated'));
    }

    /**
     * @param array<string,mixed> $methodConfig
     * @param array<string,mixed> $prices
     */
    private function pickAdminEstimateBaseRate(array $methodConfig, array $prices, ?float $fallbackRate): ?float
    {
        $priceSource = $this->getMethodPriceSource($methodConfig);
        $candidates = [
            'estimated' => $prices['estimated_cost'] ?? null,
            'gross' => $prices['gross_amount'] ?? null,
            'net' => $prices['net_amount'] ?? null,
            'fallback' => $fallbackRate,
            'manual_norgespakke' => $fallbackRate,
        ];

        $primary = $candidates[$priceSource] ?? null;
        if (is_numeric($primary)) {
            return (float) $primary;
        }

        foreach (['estimated_cost', 'gross_amount', 'net_amount', 'price', 'total'] as $key) {
            if (isset($prices[$key]) && is_numeric($prices[$key])) {
                return (float) $prices[$key];
            }
        }

        return $fallbackRate;
    }

    /**
     * @param array<string,mixed> $package
     * @return array<int,array<string,mixed>>
     */
    private function extractPackagesFromPackageInput(array $package): array
    {
        $colli = isset($package['colli']) && is_array($package['colli']) ? $package['colli'] : [];
        $normalized = [];
        foreach ($colli as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized[] = [
                'description' => sanitize_text_field((string) ($item['description'] ?? 'Pakke')),
                'length' => (float) ($item['length'] ?? 0),
                'width' => (float) ($item['width'] ?? 0),
                'height' => (float) ($item['height'] ?? 0),
                'weight' => (float) ($item['weight'] ?? 0),
            ];
        }

        if ($normalized !== []) {
            return $normalized;
        }

        return [[
            'description' => 'Pakke',
            'length' => 10.0,
            'width' => 10.0,
            'height' => 10.0,
            'weight' => max(0.01, (float) ($package['weight'] ?? 0.01)),
        ]];
    }
}
