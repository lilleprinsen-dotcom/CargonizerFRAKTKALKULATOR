<?php

namespace Lilleprinsen\Cargonizer\Tests\Unit;

use Lilleprinsen\Cargonizer\API\CargonizerClient;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;
use PHPUnit\Framework\TestCase;

final class CargonizerClientTest extends TestCase
{
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
