<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

final class SettingsRepository
{
    public const OPTION_KEY = 'lp_cargonizer_settings';

    public function get(): array
    {
        $settings = get_option(self::OPTION_KEY, []);

        return is_array($settings) ? $settings : [];
    }

    public function save(array $settings): bool
    {
        return update_option(self::OPTION_KEY, $settings);
    }
}
