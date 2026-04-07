<?php

namespace Lilleprinsen\Cargonizer\Shipping;

use Lilleprinsen\Cargonizer\Infrastructure\CargonizerClient;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;
use Lilleprinsen\Cargonizer\Shipping\Methods\CargonizerShippingMethod;

final class ShippingMethodRegistry
{
    private SettingsService $settings;
    private CargonizerClient $client;
    private RateCalculator $rateCalculator;

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

    /**
     * @param array<string,mixed> $methodConfig
     * @param array<string,mixed> $package
     */
    public function resolveRate(array $methodConfig, array $package): ?float
    {
        $cacheKey = $this->buildRateCacheKey($methodConfig, $package);
        $cached = get_transient($cacheKey);

        if (is_numeric($cached)) {
            return $this->validateRate((float) $cached);
        }

        $quote = $this->client->fetchRateQuote([
            'agreement_id' => (string) ($methodConfig['agreement_id'] ?? ''),
            'product_id' => (string) ($methodConfig['product_id'] ?? ''),
            'package' => $package,
        ]);

        $rawRate = is_array($quote) && isset($quote['price']) && is_numeric($quote['price'])
            ? (float) $quote['price']
            : null;

        if ($rawRate === null) {
            $rawRate = $this->getFallbackRate($methodConfig);
        }

        if ($rawRate === null) {
            return null;
        }

        $calculated = $this->rateCalculator->calculate($rawRate, $this->settings->getPricingModifiers());
        $validated = $this->validateRate($calculated);

        if ($validated === null) {
            return null;
        }

        set_transient($cacheKey, $validated, 10 * MINUTE_IN_SECONDS);

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
            'destination' => $package['destination'] ?? [],
            'contents' => array_map(static function ($item): array {
                if (!is_array($item)) {
                    return [];
                }

                return [
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'variation_id' => (int) ($item['variation_id'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'line_total' => (float) ($item['line_total'] ?? 0),
                    'line_tax' => (float) ($item['line_tax'] ?? 0),
                ];
            }, is_array($package['contents'] ?? null) ? $package['contents'] : []),
        ];

        return 'lp_carg_rate_' . md5(wp_json_encode($parts));
    }

    private function validateRate(float $rate): ?float
    {
        if (!is_finite($rate) || $rate < 0) {
            return null;
        }

        return round($rate, 2);
    }
}
