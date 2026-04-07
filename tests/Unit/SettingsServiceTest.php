<?php

namespace Lilleprinsen\Cargonizer\Tests\Unit;

use Lilleprinsen\Cargonizer\Infrastructure\SettingsRepository;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;
use PHPUnit\Framework\TestCase;

final class SettingsServiceTest extends TestCase
{
    public function testSanitizeSettingsNormalizesFields(): void
    {
        $service = new SettingsService(new SettingsRepository());

        $clean = $service->sanitizeSettings([
            'enabled_methods' => ['A!_1', 'A!_1', 'b-2'],
            'fallback_rates' => ['bad key' => '-10', 'ok' => '12.5'],
            'rounding_precision' => 99,
        ]);

        self::assertSame(['a_1', 'b-2'], $clean['enabled_methods']);
        self::assertSame(['badkey' => 0.0, 'ok' => 12.5], $clean['fallback_rates']);
        self::assertSame(4, $clean['rounding_precision']);
    }

    public function testSanitizeSettingsKeepsExistingSecretsWhenBlank(): void
    {
        $service = new SettingsService(new SettingsRepository());

        $clean = $service->sanitizeSettings([
            'api_key' => '',
            'sender_id' => '',
        ], [
            'api_key' => 'enc:v1:abc',
            'sender_id' => 'enc:v1:def',
        ]);

        self::assertSame('enc:v1:abc', $clean['api_key']);
        self::assertSame('enc:v1:def', $clean['sender_id']);
    }

    public function testSanitizeSettingsPreservesSenderRelationIdCharacters(): void
    {
        $service = new SettingsService(new SettingsRepository());

        $clean = $service->sanitizeSettings([
            'sender_id' => '  user-rel_01-ABC  ',
        ]);

        self::assertSame('user-rel_01-ABC', $clean['sender_id']);
    }
}
