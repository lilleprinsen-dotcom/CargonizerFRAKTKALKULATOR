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

    public function save(array $settings): bool
    {
        $clean = [
            'api_key' => sanitize_text_field((string) ($settings['api_key'] ?? '')),
            'sender_id' => sanitize_text_field((string) ($settings['sender_id'] ?? '')),
            'enabled_methods' => isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? array_values($settings['enabled_methods']) : [],
        ];

        return $this->repository->save($clean);
    }
}
