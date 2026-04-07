<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

final class SettingsService
{
    private SettingsRepository $repository;

    public function __construct(SettingsRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getSettings(): array
    {
        return $this->repository->get();
    }

    public function getApiKey(): string
    {
        return (string) ($this->getSettings()['api_key'] ?? '');
    }

    public function getSenderId(): string
    {
        return (string) ($this->getSettings()['sender_id'] ?? '');
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
        $clean = [
            'api_key' => sanitize_text_field((string) ($settings['api_key'] ?? '')),
            'sender_id' => sanitize_text_field((string) ($settings['sender_id'] ?? '')),
            'enabled_methods' => isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? array_values($settings['enabled_methods']) : [],
            'available_methods' => isset($settings['available_methods']) && is_array($settings['available_methods']) ? array_values($settings['available_methods']) : [],
            'discount_percent' => $this->toFloat($settings['discount_percent'] ?? 0),
            'fuel_percent' => $this->toFloat($settings['fuel_percent'] ?? 0),
            'toll_fee' => $this->toFloat($settings['toll_fee'] ?? 0),
            'handling_fee' => $this->toFloat($settings['handling_fee'] ?? 0),
            'vat_percent' => $this->toFloat($settings['vat_percent'] ?? 0),
            'rounding_precision' => (int) ($settings['rounding_precision'] ?? 2),
            'fallback_rates' => isset($settings['fallback_rates']) && is_array($settings['fallback_rates']) ? $settings['fallback_rates'] : [],
            'rate_api_url' => esc_url_raw((string) ($settings['rate_api_url'] ?? '')),
        ];

        return $this->repository->save($clean);
    }

    /**
     * @param mixed $value
     */
    private function toFloat($value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
