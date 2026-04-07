<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

final class SettingsService
{
    private const ENCRYPTED_PREFIX = 'enc:v1:';
    private SettingsRepository $repository;

    public function __construct(SettingsRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getSettings(): array
    {
        return $this->validateStoredSettings($this->repository->get());
    }

    public function getApiKey(): string
    {
        return $this->decryptSecret((string) ($this->getSettings()['api_key'] ?? ''));
    }

    public function getSenderId(): string
    {
        return $this->decryptSecret((string) ($this->getSettings()['sender_id'] ?? ''));
    }

    public function getPricingModifiers(): array
    {
        $settings = $this->getSettings();

        return [
            'discount_percent' => $this->toFloat($settings['discount_percent'] ?? 0),
            'fuel_percent' => $this->toFloat($settings['fuel_percent'] ?? 0),
            'toll_fee' => $this->toFloat($settings['toll_fee'] ?? 0),
            'handling_fee' => $this->toFloat($settings['handling_fee'] ?? 0),
            'vat_percent' => $this->toFloat($settings['vat_percent'] ?? 0),
            'rounding_precision' => (int) ($settings['rounding_precision'] ?? 2),
        ];
    }

    /**
     * @return array<string,float>
     */
    public function getStaticFallbackRates(): array
    {
        $raw = $this->getSettings()['fallback_rates'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $methodId => $rate) {
            $id = sanitize_key((string) $methodId);
            if ($id === '' || !is_numeric($rate)) {
                continue;
            }

            $clean[$id] = max(0.0, (float) $rate);
        }

        return $clean;
    }

    public function save(array $settings): bool
    {
        $existing = $this->repository->get();
        $clean = $this->sanitizeSettings($settings, $existing);

        return $this->repository->save($clean);
    }

    public function sanitizeSettings(array $settings, array $existing = []): array
    {
        $apiKeyRaw = sanitize_text_field((string) ($settings['api_key'] ?? ''));
        $senderRaw = sanitize_text_field((string) ($settings['sender_id'] ?? ''));

        return [
            'api_key' => $this->normalizeSecret($apiKeyRaw, (string) ($existing['api_key'] ?? '')),
            'sender_id' => $this->normalizeSecret($senderRaw, (string) ($existing['sender_id'] ?? '')),
            'enabled_methods' => $this->sanitizeEnabledMethods($settings['enabled_methods'] ?? []),
            'available_methods' => $this->sanitizeAvailableMethods($settings['available_methods'] ?? []),
            'method_pricing' => $this->sanitizeMethodPricing($settings['method_pricing'] ?? []),
            'discount_percent' => $this->toFloat($settings['discount_percent'] ?? 0),
            'fuel_percent' => $this->toFloat($settings['fuel_percent'] ?? 0),
            'toll_fee' => $this->toFloat($settings['toll_fee'] ?? 0),
            'handling_fee' => $this->toFloat($settings['handling_fee'] ?? 0),
            'vat_percent' => $this->toFloat($settings['vat_percent'] ?? 0),
            'rounding_precision' => max(0, min(4, (int) ($settings['rounding_precision'] ?? 2))),
            'fallback_rates' => $this->sanitizeFallbackRates($settings['fallback_rates'] ?? []),
            'rate_api_url' => esc_url_raw((string) ($settings['rate_api_url'] ?? '')),
        ];
    }

    /**
     * @param mixed $value
     */
    private function toFloat($value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param mixed $methods
     * @return array<int,string>
     */
    private function sanitizeEnabledMethods($methods): array
    {
        if (!is_array($methods)) {
            return [];
        }

        $clean = [];
        foreach ($methods as $methodId) {
            $id = sanitize_key((string) $methodId);
            if ($id !== '') {
                $clean[] = $id;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param mixed $methods
     * @return array<int,array<string,mixed>>
     */
    private function sanitizeAvailableMethods($methods): array
    {
        if (!is_array($methods)) {
            return [];
        }

        $clean = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $methodId = sanitize_key((string) ($method['method_id'] ?? ''));
            if ($methodId === '') {
                continue;
            }

            $clean[] = [
                'instance_id' => max(1, (int) ($method['instance_id'] ?? 0)),
                'method_id' => $methodId,
                'carrier_name' => sanitize_text_field((string) ($method['carrier_name'] ?? '')),
                'carrier_id' => sanitize_text_field((string) ($method['carrier_id'] ?? '')),
                'agreement_id' => sanitize_text_field((string) ($method['agreement_id'] ?? '')),
                'agreement_name' => sanitize_text_field((string) ($method['agreement_name'] ?? '')),
                'agreement_description' => sanitize_text_field((string) ($method['agreement_description'] ?? '')),
                'agreement_number' => sanitize_text_field((string) ($method['agreement_number'] ?? '')),
                'product_id' => sanitize_text_field((string) ($method['product_id'] ?? '')),
                'product_name' => sanitize_text_field((string) ($method['product_name'] ?? '')),
                'services' => $this->sanitizeServices($method['services'] ?? []),
                'title' => sanitize_text_field((string) ($method['title'] ?? '')),
                'enabled' => (string) ($method['enabled'] ?? '') === 'no' ? 'no' : 'yes',
                'fallback_rate' => max(0.0, $this->toFloat($method['fallback_rate'] ?? 0)),
            ];
        }

        return array_values($clean);
    }

    /**
     * @param mixed $pricing
     * @return array<string,array<string,float>>
     */
    private function sanitizeMethodPricing($pricing): array
    {
        if (!is_array($pricing)) {
            return [];
        }

        $clean = [];
        foreach ($pricing as $methodId => $config) {
            $id = sanitize_key((string) $methodId);
            if ($id === '' || !is_array($config)) {
                continue;
            }

            $priceSource = sanitize_key((string) ($config['price_source'] ?? 'estimated'));
            if (!in_array($priceSource, ['estimated', 'net', 'gross', 'fallback', 'manual_norgespakke'], true)) {
                $priceSource = 'estimated';
            }

            $roundingMode = sanitize_key((string) ($config['rounding_mode'] ?? 'none'));
            if (!in_array($roundingMode, ['none', 'nearest_1', 'nearest_10', 'price_ending_9'], true)) {
                $roundingMode = 'none';
            }

            $clean[$id] = [
                'price_source' => $priceSource,
                'discount_percent' => $this->toFloat($config['discount_percent'] ?? 0),
                'fuel_surcharge' => $this->toFloat($config['fuel_surcharge'] ?? 0),
                'fuel_percent' => $this->toFloat($config['fuel_surcharge'] ?? ($config['fuel_percent'] ?? 0)),
                'toll_surcharge' => max(0.0, $this->toFloat($config['toll_surcharge'] ?? 0)),
                'toll_fee' => max(0.0, $this->toFloat($config['toll_surcharge'] ?? ($config['toll_fee'] ?? 0))),
                'handling_fee' => max(0.0, $this->toFloat($config['handling_fee'] ?? 0)),
                'vat_percent' => max(0.0, $this->toFloat($config['vat_percent'] ?? 0)),
                'rounding_mode' => $roundingMode,
                'delivery_to_pickup_point' => max(0.0, $this->toFloat($config['delivery_to_pickup_point'] ?? 0)),
                'delivery_to_home' => max(0.0, $this->toFloat($config['delivery_to_home'] ?? 0)),
                'manual_norgespakke_include_handling' => !empty($config['manual_norgespakke_include_handling']) ? 1 : 0,
            ];
        }

        return $clean;
    }

    /**
     * @param mixed $rates
     * @return array<string,float>
     */
    private function sanitizeFallbackRates($rates): array
    {
        if (!is_array($rates)) {
            return [];
        }

        $clean = [];
        foreach ($rates as $methodId => $rate) {
            $id = sanitize_key((string) $methodId);
            if ($id === '' || !is_numeric($rate)) {
                continue;
            }

            $clean[$id] = max(0.0, (float) $rate);
        }

        return $clean;
    }

    /**
     * @param mixed $services
     * @return array<int,string>
     */
    private function sanitizeServices($services): array
    {
        if (!is_array($services)) {
            return [];
        }

        $clean = [];
        foreach ($services as $service) {
            $name = sanitize_text_field((string) $service);
            if ($name !== '') {
                $clean[] = $name;
            }
        }

        return array_values(array_unique($clean));
    }

    private function normalizeSecret(string $rawInput, string $existingEncrypted): string
    {
        if ($rawInput === '') {
            return $existingEncrypted;
        }

        if (strpos($rawInput, '*****') !== false && $existingEncrypted !== '') {
            return $existingEncrypted;
        }

        return $this->encryptSecret($rawInput);
    }

    private function validateStoredSettings(array $settings): array
    {
        $apiKey = (string) ($settings['api_key'] ?? '');
        $senderId = (string) ($settings['sender_id'] ?? '');

        return [
            'api_key' => strpos($apiKey, self::ENCRYPTED_PREFIX) === 0 ? $apiKey : sanitize_text_field($apiKey),
            'sender_id' => strpos($senderId, self::ENCRYPTED_PREFIX) === 0 ? $senderId : sanitize_text_field($senderId),
            'enabled_methods' => $this->sanitizeEnabledMethods($settings['enabled_methods'] ?? []),
            'available_methods' => $this->sanitizeAvailableMethods($settings['available_methods'] ?? []),
            'method_pricing' => $this->sanitizeMethodPricing($settings['method_pricing'] ?? []),
            'discount_percent' => $this->toFloat($settings['discount_percent'] ?? 0),
            'fuel_percent' => $this->toFloat($settings['fuel_percent'] ?? 0),
            'toll_fee' => $this->toFloat($settings['toll_fee'] ?? 0),
            'handling_fee' => $this->toFloat($settings['handling_fee'] ?? 0),
            'vat_percent' => $this->toFloat($settings['vat_percent'] ?? 0),
            'rounding_precision' => max(0, min(4, (int) ($settings['rounding_precision'] ?? 2))),
            'fallback_rates' => $this->sanitizeFallbackRates($settings['fallback_rates'] ?? []),
            'rate_api_url' => esc_url_raw((string) ($settings['rate_api_url'] ?? '')),
        ];
    }

    private function encryptSecret(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        if (!$this->canEncrypt()) {
            return $plain;
        }

        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if ($ivLength < 1) {
            return $plain;
        }

        $iv = random_bytes($ivLength);
        $ciphertext = openssl_encrypt($plain, 'aes-256-cbc', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv);
        if (!is_string($ciphertext) || $ciphertext === '') {
            return $plain;
        }

        return self::ENCRYPTED_PREFIX . base64_encode($iv . $ciphertext);
    }

    private function decryptSecret(string $stored): string
    {
        if ($stored === '' || strpos($stored, self::ENCRYPTED_PREFIX) !== 0) {
            return $stored;
        }

        if (!$this->canEncrypt()) {
            return '';
        }

        $payload = base64_decode(substr($stored, strlen(self::ENCRYPTED_PREFIX)), true);
        if (!is_string($payload) || $payload === '') {
            return '';
        }

        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if ($ivLength < 1 || strlen($payload) <= $ivLength) {
            return '';
        }

        $iv = substr($payload, 0, $ivLength);
        $ciphertext = substr($payload, $ivLength);
        $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv);

        return is_string($plain) ? $plain : '';
    }

    private function canEncrypt(): bool
    {
        return function_exists('openssl_encrypt') && function_exists('openssl_decrypt') && function_exists('wp_salt');
    }

    private function encryptionKey(): string
    {
        return hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    }
}
