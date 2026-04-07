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

        $calculated = $this->rateCalculator->calculate($rawRate, $this->getMethodPricingConfig($methodConfig), $quoteRequest);
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
        $customParams = $this->resolveCarrierSpecificCustomParams($methodConfig);
        $services = [];
        $smsEnabled = (string) ($methodConfig['sms_enabled'] ?? '') === 'yes';
        if ($smsEnabled) {
            $smsServiceId = $this->resolveSmsServiceId($methodConfig);
            if ($smsServiceId !== '') {
                $services[] = ['id' => $smsServiceId];
            }
        }

        $packages = $this->extractPackagesFromPackageInput($package);
        $isManualNorgespakkeMethod = sanitize_key((string) ($methodConfig['key'] ?? '')) === 'manualnorgespakke';
        $result = $isManualNorgespakkeMethod
            ? [
                'prices' => [],
                'requirements' => [],
                'errors' => [],
            ]
            : $this->client->estimateConsignmentCost(
                $recipient,
                $packages,
                $methodConfig,
                [
                    'service_partner' => sanitize_text_field((string) ($methodConfig['service_partner'] ?? '')),
                    'services' => $services,
                    'sms_service_id' => isset($services[0]['id']) ? (string) $services[0]['id'] : '',
                    'custom_params' => $customParams,
                ]
            );

        $methodPricing = $this->getMethodPricingConfig($methodConfig);
        $prices = is_array($result['prices'] ?? null) ? $result['prices'] : [];
        $manualPricing = $this->buildManualNorgespakkePricing($packages);
        $priceFields = $this->parseEstimatePriceFields($prices, $fallbackRate, $manualPricing['base_price_ex_vat']);
        $sourcePriority = $this->getPriceSourcePriority((string) ($methodPricing['price_source'] ?? 'estimated'));
        $selectedSource = $this->selectEstimatePriceValue($priceFields, $sourcePriority);
        $pricingComputation = $this->calculateEstimateFromPriceSource($selectedSource, $methodPricing, $methodConfig, $manualPricing);
        $computedRate = isset($pricingComputation['rounded_rate']) && is_numeric($pricingComputation['rounded_rate'])
            ? $this->validateRate((float) $pricingComputation['rounded_rate'])
            : null;
        $deliveryFlags = [];
        if ((float) ($methodPricing['delivery_to_pickup_point'] ?? 0) > 0) {
            $deliveryFlags[] = 'HENTESTED';
        }
        if ((float) ($methodPricing['delivery_to_home'] ?? 0) > 0) {
            $deliveryFlags[] = 'HJEMLEVERING';
        }

        return [
            'method_id' => $methodId,
            'title' => (string) ($methodConfig['title'] ?? $methodId),
            'rate' => $computedRate,
            'price_source' => (string) ($methodPricing['price_source'] ?? 'estimated'),
            'fallback_rate' => $fallbackRate,
            'fallback_reason' => (string) ($selectedSource['fallback_reason'] ?? ''),
            'estimate' => $result,
            'estimate_debug' => [
                'errors' => $this->parseResponseErrorDetails($result),
                'price_fields' => $priceFields,
                'source_priority' => $sourcePriority,
                'selected_source' => $selectedSource,
                'manual_norgespakke' => $manualPricing,
                'calculation' => $pricingComputation,
            ],
            'supports_sms' => $this->resolveSmsServiceId($methodConfig) !== '',
            'delivery_flags' => $deliveryFlags,
            'method_pricing' => $methodPricing,
        ];
    }

    /**
     * @param array<string,mixed> $methodConfig
     * @param array<string,mixed> $destination
     * @return array<int,array<string,string>>
     */
    public function getServicepartnerOptions(array $methodConfig, array $destination): array
    {
        $payload = $this->client->fetchServicePartners();
        if (!is_string($payload['raw'] ?? null) || !function_exists('simplexml_load_string')) {
            return [];
        }

        $document = @simplexml_load_string((string) $payload['raw']);
        if ($document === false) {
            return [];
        }

        $carrierId = sanitize_text_field((string) ($methodConfig['carrier_id'] ?? ''));
        $country = strtoupper(sanitize_text_field((string) ($destination['country'] ?? '')));
        $postcode = sanitize_text_field((string) ($destination['postcode'] ?? ''));
        $productId = sanitize_text_field((string) ($methodConfig['product_id'] ?? ''));
        $agreementId = sanitize_text_field((string) ($methodConfig['agreement_id'] ?? ''));

        $nodes = $document->xpath('//service_partner');
        if (!is_array($nodes)) {
            return [];
        }

        $options = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \SimpleXMLElement) {
                continue;
            }

            $candidateCarrier = $this->xmlValue($node, ['carrier/id', 'carrier_id', 'carrier']);
            $candidateCountry = strtoupper($this->xmlValue($node, ['country', 'address/country', 'visitor_address/country']));
            $candidatePostcode = $this->xmlValue($node, ['postcode', 'zip', 'address/postcode', 'visitor_address/postcode']);
            $candidateAgreement = $this->xmlValue($node, ['transport_agreement/id', 'transport_agreement_id', 'agreement_id']);
            $candidateProduct = $this->xmlValue($node, ['product/id', 'product_id']);

            if ($carrierId !== '' && $candidateCarrier !== '' && $candidateCarrier !== $carrierId) {
                continue;
            }

            if ($country !== '' && $candidateCountry !== '' && $candidateCountry !== $country) {
                continue;
            }

            if ($postcode !== '' && $candidatePostcode !== '' && stripos($postcode, $candidatePostcode) !== 0 && stripos($candidatePostcode, $postcode) !== 0) {
                continue;
            }

            if ($agreementId !== '' && $candidateAgreement !== '' && $candidateAgreement !== $agreementId) {
                continue;
            }

            if ($productId !== '' && $candidateProduct !== '' && $candidateProduct !== $productId) {
                continue;
            }

            $id = $this->xmlValue($node, ['number', 'id', 'service_partner_id']);
            if ($id === '') {
                continue;
            }

            $options[] = [
                'id' => sanitize_text_field($id),
                'name' => sanitize_text_field($this->xmlValue($node, ['name', 'service_partner_name'])),
            ];
        }

        return array_values($options);
    }

    /**
     * @param array<string,mixed> $methodConfig
     * @return array<string,string>
     */
    private function resolveCarrierSpecificCustomParams(array $methodConfig): array
    {
        $carrier = strtolower((string) ($methodConfig['carrier_name'] ?? ''));
        $productNeedLocker = $this->productIndicatesLocker($methodConfig);

        if (strpos($carrier, 'bring') !== false) {
            return ['pickupPointType' => $productNeedLocker ? 'locker' : 'pickup_point'];
        }

        if (strpos($carrier, 'postnord') !== false) {
            return ['typeId' => $productNeedLocker ? 'service_point' : 'pickup'];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $methodConfig
     */
    private function productIndicatesLocker(array $methodConfig): bool
    {
        $productHaystack = strtolower(trim((string) (($methodConfig['product_id'] ?? '') . ' ' . ($methodConfig['product_name'] ?? ''))));
        if ($productHaystack === '') {
            return false;
        }

        return strpos($productHaystack, 'locker') !== false
            || strpos($productHaystack, 'pakkeautomat') !== false
            || strpos($productHaystack, 'parcel box') !== false;
    }

    /**
     * @param array<string,mixed> $methodConfig
     */
    private function resolveSmsServiceId(array $methodConfig): string
    {
        $services = isset($methodConfig['services']) && is_array($methodConfig['services']) ? $methodConfig['services'] : [];
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }

            $serviceId = sanitize_text_field((string) ($service['service_id'] ?? ''));
            $serviceName = strtolower(sanitize_text_field((string) ($service['service_name'] ?? '')));
            if ($serviceId === '') {
                continue;
            }

            if (strpos(strtolower($serviceId), 'sms') !== false || strpos($serviceName, 'sms') !== false) {
                return $serviceId;
            }
        }

        return '';
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

        $agreements = $document->xpath('//transport_agreement | //transport-agreement');
        if (!is_array($agreements)) {
            return [];
        }

        foreach ($agreements as $agreement) {
            $agreementId = $this->xmlValue($agreement, ['agreement_id', 'agreement-id', 'identifier', 'id']);
            $agreementName = $this->xmlValue($agreement, ['agreement_name', 'agreement-name', 'agreement_description', 'agreement-description', 'description', 'name']);
            $agreementDescription = $this->xmlValue($agreement, ['agreement_description', 'agreement-description', 'description', 'agreement_name', 'agreement-name', 'name']);
            $agreementNumber = $this->xmlValue($agreement, ['agreement_number', 'agreement-number', 'number']);

            $carrierId = $this->xmlValue($agreement, [
                'carrier/carrier_id',
                'carrier/carrier-id',
                'carrier/identifier',
                'carrier/id',
                'carrier_id',
                'carrier-id',
            ]);
            $carrierName = $this->xmlValue($agreement, [
                'carrier/carrier_name',
                'carrier/carrier-name',
                'carrier/name',
                'carrier_name',
                'carrier-name',
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
                $productId = $this->xmlValue($product, ['product_id', 'product-id', 'identifier', 'id']);
                $productName = $this->xmlValue($product, ['product_name', 'product-name', 'name']);

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
                        $serviceId = $this->xmlValue($serviceNode, ['service_id', 'service-id', 'identifier', 'id']);
                        $serviceName = $this->xmlValue($serviceNode, ['service_name', 'service-name', 'name']);
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
     * @return array<string,mixed>
     */
    private function getMethodPricingConfig(array $methodConfig): array
    {
        $settings = $this->settings->getSettings();
        $methodPricing = is_array($settings['method_pricing'] ?? null) ? $settings['method_pricing'] : [];
        $methodId = sanitize_key((string) ($methodConfig['method_id'] ?? ''));

        return is_array($methodPricing[$methodId] ?? null) ? $methodPricing[$methodId] : [];
    }

    /**
     * @param array<string,mixed> $result
     * @return array<int,array<string,string>>
     */
    private function parseResponseErrorDetails(array $result): array
    {
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $normalized = [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $normalized[] = [
                'code' => sanitize_text_field((string) ($error['code'] ?? '')),
                'field' => sanitize_text_field((string) ($error['field'] ?? '')),
                'message' => sanitize_text_field((string) ($error['message'] ?? '')),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $prices
     * @return array<string,float|null>
     */
    private function parseEstimatePriceFields(array $prices, ?float $fallbackRate, ?float $manualNorgespakkePrice): array
    {
        $parsed = [];
        $parsed['estimated'] = isset($prices['estimated_cost']) && is_numeric($prices['estimated_cost']) ? (float) $prices['estimated_cost'] : null;
        $parsed['gross'] = isset($prices['gross_amount']) && is_numeric($prices['gross_amount']) ? (float) $prices['gross_amount'] : null;
        $parsed['net'] = isset($prices['net_amount']) && is_numeric($prices['net_amount']) ? (float) $prices['net_amount'] : null;
        $parsed['fallback'] = $fallbackRate;
        $parsed['manual_norgespakke'] = $manualNorgespakkePrice;
        $parsed['price'] = isset($prices['price']) && is_numeric($prices['price']) ? (float) $prices['price'] : null;
        $parsed['total'] = isset($prices['total']) && is_numeric($prices['total']) ? (float) $prices['total'] : null;

        return $parsed;
    }

    /**
     * @return array<int,string>
     */
    private function getPriceSourcePriority(string $configuredSource): array
    {
        $basePriority = ['estimated', 'gross', 'net', 'fallback', 'manual_norgespakke'];
        $configured = sanitize_key($configuredSource);
        if (!in_array($configured, $basePriority, true)) {
            $configured = 'estimated';
        }

        $priority = [$configured];
        foreach ($basePriority as $source) {
            if ($source !== $configured) {
                $priority[] = $source;
            }
        }

        return $priority;
    }

    /**
     * @param array<string,float|null> $priceFields
     * @param array<int,string> $sourcePriority
     * @return array<string,mixed>
     */
    private function selectEstimatePriceValue(array $priceFields, array $sourcePriority): array
    {
        foreach ($sourcePriority as $source) {
            if (!array_key_exists($source, $priceFields)) {
                continue;
            }

            $value = $priceFields[$source];
            if (is_numeric($value)) {
                $fallbackReason = $source === $sourcePriority[0] ? 'configured_source_available' : 'configured_source_unavailable';

                return [
                    'source' => $source,
                    'value' => (float) $value,
                    'fallback_reason' => $fallbackReason,
                ];
            }
        }

        foreach (['price', 'total'] as $legacySource) {
            $legacyValue = $priceFields[$legacySource] ?? null;
            if (is_numeric($legacyValue)) {
                return [
                    'source' => $legacySource,
                    'value' => (float) $legacyValue,
                    'fallback_reason' => 'fallback_to_legacy_' . $legacySource,
                ];
            }
        }

        return [
            'source' => '',
            'value' => null,
            'fallback_reason' => 'no_price_source_available',
        ];
    }

    /**
     * @param array<string,mixed> $selectedSource
     * @param array<string,mixed> $methodPricing
     * @return array<string,mixed>
     */
    private function calculateEstimateFromPriceSource(array $selectedSource, array $methodPricing, array $methodConfig, array $manualPricing): array
    {
        $selectedValue = $selectedSource['value'] ?? null;
        if (!is_numeric($selectedValue)) {
            return [
                'rounded_rate' => null,
                'calculation_status' => 'missing_selected_value',
                'manual_norgespakke' => $manualPricing,
            ];
        }

        if (($selectedSource['source'] ?? '') === 'manual_norgespakke' && !empty($manualPricing['rejected'])) {
            return [
                'rounded_rate' => null,
                'calculation_status' => 'manual_norgespakke_rejected',
                'manual_norgespakke' => $manualPricing,
            ];
        }

        $listPrice = max(0.0, (float) $selectedValue);
        $discountPercent = max(0.0, min(100.0, (float) ($methodPricing['discount_percent'] ?? 0)));
        $fuelPercent = max(0.0, min(100.0, (float) ($methodPricing['fuel_percent'] ?? ($methodPricing['fuel_surcharge'] ?? 0))));
        $tollFee = max(0.0, (float) ($methodPricing['toll_fee'] ?? ($methodPricing['toll_surcharge'] ?? 0)));
        $configuredHandlingFee = max(0.0, (float) ($methodPricing['handling_fee'] ?? 0));
        $vatPercent = max(0.0, min(100.0, (float) ($methodPricing['vat_percent'] ?? 0)));
        $manualHandlingFee = max(0.0, (float) ($manualPricing['handling_fee_ex_vat'] ?? 0));
        $isManualSource = ($selectedSource['source'] ?? '') === 'manual_norgespakke';
        $isBringMethod = $this->isBringMethod($methodConfig);

        $includeManualHandlingForManualSource = $isManualSource && !empty($methodPricing['manual_norgespakke_include_handling']);
        if ($includeManualHandlingForManualSource) {
            $listPrice += $manualHandlingFee;
        }

        $handlingFee = $configuredHandlingFee + ($includeManualHandlingForManualSource ? $manualHandlingFee : 0.0);

        $fuelMultiplier = 1 + ($fuelPercent / 100);
        $baseFreightBeforeDiscount = max(0.0, ($listPrice - $tollFee - $handlingFee) / $fuelMultiplier);
        $discountAmount = $baseFreightBeforeDiscount * ($discountPercent / 100);
        $discountedBaseFreight = max(0.0, $baseFreightBeforeDiscount - $discountAmount);
        $fuelAmount = $discountedBaseFreight * ($fuelPercent / 100);
        $subtotalExVat = $discountedBaseFreight + $fuelAmount + $tollFee + $handlingFee;
        if (!$isManualSource && $isBringMethod) {
            $subtotalExVat += $manualHandlingFee;
        }
        $vatAmount = $subtotalExVat * ($vatPercent / 100);
        $totalBeforeRounding = $subtotalExVat + $vatAmount;

        $roundedRate = $this->applyRoundingMode($totalBeforeRounding, (string) ($methodPricing['rounding_mode'] ?? 'none'));

        return [
            'calculation_status' => 'ok',
            'list_price_including_fees' => $listPrice,
            'base_freight_before_discount' => $baseFreightBeforeDiscount,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'discounted_base_freight' => $discountedBaseFreight,
            'fuel_percent' => $fuelPercent,
            'fuel_amount' => $fuelAmount,
            'toll_fee' => $tollFee,
            'handling_fee' => $handlingFee,
            'manual_handling_fee' => $manualHandlingFee,
            'manual_handling_package_count' => (int) ($manualPricing['handling_package_count'] ?? 0),
            'manual_handling_reasons' => is_array($manualPricing['handling_reasons'] ?? null) ? array_values($manualPricing['handling_reasons']) : [],
            'subtotal_ex_vat' => $subtotalExVat,
            'vat_percent' => $vatPercent,
            'vat_amount' => $vatAmount,
            'total_before_rounding' => $totalBeforeRounding,
            'rounding_mode' => (string) ($methodPricing['rounding_mode'] ?? 'none'),
            'rounded_rate' => $roundedRate,
            'final_ex_vat_price' => $subtotalExVat,
            'manual_norgespakke' => $manualPricing,
        ];
    }

    /**
     * @param array<string,mixed> $methodConfig
     */
    private function isBringMethod(array $methodConfig): bool
    {
        $carrierName = strtolower((string) ($methodConfig['carrier_name'] ?? ''));
        $carrierId = strtolower((string) ($methodConfig['carrier_id'] ?? ''));
        $title = strtolower((string) ($methodConfig['title'] ?? ''));

        return strpos($carrierName, 'bring') !== false
            || strpos($carrierId, 'bring') !== false
            || strpos($title, 'bring') !== false;
    }

    /**
     * @param array<int,array<string,mixed>> $packages
     * @return array<string,mixed>
     */
    private function buildManualNorgespakkePricing(array $packages): array
    {
        $packageDebug = [];
        $baseSubtotalExVat = 0.0;
        $handlingPackageCount = 0;
        $rejected = false;
        $rejectionReason = '';
        $handlingReasons = [];

        foreach ($packages as $index => $package) {
            $weight = max(0.0, (float) ($package['weight'] ?? 0));
            $length = max(0.0, (float) ($package['length'] ?? 0));
            $width = max(0.0, (float) ($package['width'] ?? 0));
            $height = max(0.0, (float) ($package['height'] ?? 0));

            $basePrice = null;
            if ($weight <= 10.0) {
                $basePrice = 112.00;
            } elseif ($weight <= 25.0) {
                $basePrice = 200.80;
            } elseif ($weight <= 35.0) {
                $basePrice = 268.00;
            } else {
                $rejected = true;
                $rejectionReason = 'weight_over_35kg';
            }

            $sides = [$length, $width, $height];
            $sidesOver60 = count(array_filter($sides, static function (float $side): bool {
                return $side > 60.0;
            }));
            $hasSideOver120 = $length > 120.0 || $width > 120.0 || $height > 120.0;
            $manualHandlingTriggered = $hasSideOver120 || $sidesOver60 >= 2;

            $manualReasonParts = [];
            if ($hasSideOver120) {
                $manualReasonParts[] = 'any_side_over_120cm';
            }
            if ($sidesOver60 >= 2) {
                $manualReasonParts[] = 'at_least_two_sides_over_60cm';
            }
            $manualReason = implode('+', $manualReasonParts);

            if ($manualHandlingTriggered) {
                $handlingPackageCount++;
                if ($manualReason !== '') {
                    $handlingReasons[] = $manualReason;
                }
            }

            if ($basePrice !== null) {
                $baseSubtotalExVat += $basePrice;
            }

            $packageDebug[] = [
                'index' => $index,
                'weight_kg' => $weight,
                'length_cm' => $length,
                'width_cm' => $width,
                'height_cm' => $height,
                'norgespakke_base_price_ex_vat' => $basePrice,
                'manual_handling_triggered' => $manualHandlingTriggered,
                'manual_handling_reason' => $manualReason,
            ];
        }

        $handlingFeeExVat = $handlingPackageCount * 164.00;

        return [
            'package_debug' => $packageDebug,
            'package_count' => count($packageDebug),
            'base_price_ex_vat' => $rejected ? null : $baseSubtotalExVat,
            'handling_package_count' => $handlingPackageCount,
            'handling_reasons' => array_values(array_unique($handlingReasons)),
            'handling_fee_ex_vat' => $handlingFeeExVat,
            'rejected' => $rejected,
            'rejection_reason' => $rejectionReason,
            'subtotal_ex_vat' => $rejected ? null : $baseSubtotalExVat + $handlingFeeExVat,
        ];
    }

    private function applyRoundingMode(float $amount, string $roundingMode): float
    {
        $rate = max(0.0, $amount);
        $mode = sanitize_key($roundingMode);

        if ($mode === 'nearest_1') {
            return (float) round($rate, 0);
        }

        if ($mode === 'nearest_10') {
            return (float) (round($rate / 10) * 10);
        }

        if ($mode === 'price_ending_9') {
            $integer = (int) ceil($rate);
            if ($integer <= 9) {
                return 9.0;
            }

            return (float) ((int) (floor($integer / 10) * 10) - 1 < $integer
                ? (int) (floor($integer / 10) * 10) + 9
                : (int) (floor($integer / 10) * 10) - 1);
        }

        return round($rate, 2);
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
