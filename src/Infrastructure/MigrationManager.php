<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

final class MigrationManager
{
    public const OPTION_DB_VERSION = 'lp_cargonizer_db_version';

    private string $targetVersion;
    private SettingsRepository $settingsRepository;

    public function __construct(string $targetVersion, SettingsRepository $settingsRepository)
    {
        $this->targetVersion = $targetVersion;
        $this->settingsRepository = $settingsRepository;
    }

    public function migrate(): void
    {
        $storedVersion = (string) get_option(self::OPTION_DB_VERSION, '0.0.0');

        if (version_compare($storedVersion, '2.1.0', '<')) {
            $this->migrateTo210();
        }

        update_option(self::OPTION_DB_VERSION, $this->targetVersion, false);
    }

    private function migrateTo210(): void
    {
        $settings = $this->settingsRepository->get();

        if (!isset($settings['rate_api_url'])) {
            $settings['rate_api_url'] = '';
        }

        if (!isset($settings['rounding_precision'])) {
            $settings['rounding_precision'] = 2;
        }

        if (!isset($settings['fallback_rates']) || !is_array($settings['fallback_rates'])) {
            $settings['fallback_rates'] = [];
        }

        $this->settingsRepository->save($settings);
    }
}
