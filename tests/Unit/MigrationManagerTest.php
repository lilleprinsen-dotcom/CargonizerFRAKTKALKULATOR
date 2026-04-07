<?php

namespace Lilleprinsen\Cargonizer\Tests\Unit;

use Lilleprinsen\Cargonizer\Infrastructure\MigrationManager;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class MigrationManagerTest extends TestCase
{
    public function testBackfillsDefaultsAndUpdatesDbVersion(): void
    {
        $repo = new SettingsRepository();
        $repo->save(['enabled_methods' => []]);

        update_option(MigrationManager::OPTION_DB_VERSION, '2.0.0');

        $manager = new MigrationManager('2.1.0', $repo);
        $manager->migrate();

        $settings = $repo->get();
        self::assertArrayHasKey('rate_api_url', $settings);
        self::assertSame('2.1.0', get_option(MigrationManager::OPTION_DB_VERSION));
    }
}
