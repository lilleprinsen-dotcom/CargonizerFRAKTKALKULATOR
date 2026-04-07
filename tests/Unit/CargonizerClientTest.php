<?php

namespace Lilleprinsen\Cargonizer\API {
    function wp_remote_get($url, $args = [])
    {
        $handler = $GLOBALS['__lp_cargonizer_wp_remote_get_handler'] ?? null;
        if (is_callable($handler)) {
            return $handler($url, $args);
        }

        return [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => '',
        ];
    }

    function wp_remote_post($url, $args = [])
    {
        $handler = $GLOBALS['__lp_cargonizer_wp_remote_post_handler'] ?? null;
        if (is_callable($handler)) {
            return $handler($url, $args);
        }

        return [
            'response' => ['code' => 200],
            'headers' => [],
            'body' => '',
        ];
    }
}

namespace Lilleprinsen\Cargonizer\Tests\Unit {

use Lilleprinsen\Cargonizer\API\CargonizerClient;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;
use PHPUnit\Framework\TestCase;

final class CargonizerClientTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['__lp_cargonizer_wp_remote_get_handler'], $GLOBALS['__lp_cargonizer_wp_remote_post_handler']);
        parent::tearDown();
    }

    public function testParseConsignmentCostEstimateXmlSupportsHyphenatedPriceNodesAndErrorAttributes(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<consignment-cost-response>
  <estimated-cost>123.45</estimated-cost>
  <gross-amount>210.50</gross-amount>
  <net-amount>168.40</net-amount>
  <total-amount>333.30</total-amount>
  <errors>
    <error code="missing_servicepartner" field="service_partner" message="Servicepartner is required" />
    <error>
      <error-code>missing_service</error-code>
      <error-message>SMS service required</error-message>
    </error>
  </errors>
</consignment-cost-response>
XML;

        $parsed = $this->invokeParser($xml);

        self::assertSame(123.45, $parsed['prices']['estimated_cost']);
        self::assertSame(210.5, $parsed['prices']['gross_amount']);
        self::assertSame(168.4, $parsed['prices']['net_amount']);
        self::assertSame(333.3, $parsed['prices']['total']);
        self::assertTrue($parsed['requirements']['servicepartner_required']);
        self::assertTrue($parsed['requirements']['sms_required']);
        self::assertSame('missing_servicepartner', $parsed['errors'][0]['code']);
        self::assertSame('service_partner', $parsed['errors'][0]['field']);
        self::assertSame('Servicepartner is required', $parsed['errors'][0]['message']);
        self::assertSame('missing_service', $parsed['errors'][1]['code']);
        self::assertSame('SMS service required', $parsed['errors'][1]['message']);
    }

    public function testParseConsignmentCostEstimateXmlPrefersDocumentedHyphenatedPriceFields(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<consignment-cost-response>
  <gross-amount>210.50</gross-amount>
  <gross_amount>111.11</gross_amount>
  <net-amount>168.40</net-amount>
  <net_amount>99.99</net_amount>
</consignment-cost-response>
XML;

        $parsed = $this->invokeParser($xml);

        self::assertSame(210.5, $parsed['prices']['gross_amount']);
        self::assertSame(168.4, $parsed['prices']['net_amount']);
    }

    public function testResolveEndpointUsesDocumentedLiveHostByDefault(): void
    {
        $settings = $this->createConfiguredMock(SettingsService::class, [
            'getSettings' => ['rate_api_url' => 'https://rates.example.test/quote'],
        ]);

        $client = new CargonizerClient($settings);
        $method = new \ReflectionMethod($client, 'resolveEndpoint');
        $method->setAccessible(true);

        self::assertSame('https://cargonizer.no/profile.xml', $method->invoke($client, 'profile'));
        self::assertSame('https://cargonizer.no/transport_agreements.xml', $method->invoke($client, 'transport_agreements'));
    }

    public function testConnectionTestSeparatesApiKeyFailureFromSenderFailure(): void
    {
        $settings = $this->createConfiguredMock(SettingsService::class, [
            'getApiKey' => 'key-123',
            'getSenderId' => 'rel-abc',
            'getSettings' => [],
        ]);

        $GLOBALS['__lp_cargonizer_wp_remote_get_handler'] = static function ($url, $args) {
            if (strpos($url, 'profile.xml') !== false) {
                return [
                    'response' => ['code' => 200],
                    'headers' => [],
                    'body' => '<profile><id>1</id></profile>',
                ];
            }

            return [
                'response' => ['code' => 401],
                'headers' => [],
                'body' => '<?xml version="1.0" encoding="UTF-8"?><errors><error>This action requires authentication</error></errors>',
            ];
        };

        $client = new CargonizerClient($settings);
        $result = $client->testConnection();

        self::assertFalse($result['ok']);
        self::assertSame(401, $result['status']);
        self::assertStringContainsString('Sender/user relation ID rejected', $result['message']);
        self::assertSame('https://cargonizer.no/transport_agreements.xml', $result['endpoint']);
    }

    /**
     * @return array<string,mixed>
     */
    private function invokeParser(string $xml): array
    {
        $settings = $this->createMock(SettingsService::class);
        $client = new CargonizerClient($settings);
        $method = new \ReflectionMethod($client, 'parseConsignmentCostEstimateXml');
        $method->setAccessible(true);

        /** @var array<string,mixed> $parsed */
        $parsed = $method->invoke($client, $xml);

        return $parsed;
    }
}
}
