<?php

namespace Lilleprinsen\Cargonizer\Tests\Unit;

use Lilleprinsen\Cargonizer\API\CargonizerClient;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;
use Lilleprinsen\Cargonizer\Shipping\RateCalculator;
use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;
use PHPUnit\Framework\TestCase;

final class ShippingMethodRegistryTest extends TestCase
{
    public function testGetMethodConfigByMethodIdReturnsMatchingMethod(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getSettings')->willReturn([
            'available_methods' => [
                [
                    'instance_id' => 7,
                    'method_id' => 'lp_cargonizer_1_2',
                    'agreement_id' => '1',
                    'product_id' => '2',
                    'title' => 'Pickup',
                    'enabled' => 'yes',
                ],
            ],
        ]);

        $client = $this->createMock(CargonizerClient::class);
        $calculator = $this->createMock(RateCalculator::class);
        $registry = new ShippingMethodRegistry($settings, $client, $client, $calculator);

        $method = $registry->getMethodConfigByMethodId('lp_cargonizer_1_2');

        self::assertSame(7, $method['instance_id']);
        self::assertSame('Pickup', $method['title']);
    }

    public function testRefreshFromCargonizerParsesTransportAgreementSchemaAndAddsManualMethod(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<transport_agreements>
  <transport_agreement>
    <agreement_id>42</agreement_id>
    <agreement_name>Bedriftsavtale</agreement_name>
    <agreement_description>Hovedavtale</agreement_description>
    <agreement_number>AGR-42</agreement_number>
    <carrier>
      <carrier_id>7</carrier_id>
      <carrier_name>Bring</carrier_name>
    </carrier>
    <products>
      <product>
        <product_id>P1</product_id>
        <product_name>Pakke til hentested</product_name>
        <services>
          <service>
            <service_id>S1</service_id>
            <service_name>Varsling</service_name>
          </service>
        </services>
      </product>
    </products>
  </transport_agreement>
</transport_agreements>
XML;

        $saved = null;
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getSettings')->willReturn(['available_methods' => []]);
        $settings->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (array $payload) use (&$saved): bool {
                $saved = $payload;

                return true;
            }));

        $client = $this->createMock(CargonizerClient::class);
        $client->method('fetchTransportAgreements')->willReturn(['raw' => $xml]);
        $calculator = $this->createMock(RateCalculator::class);
        $registry = new ShippingMethodRegistry($settings, $client, $client, $calculator);

        $methods = $registry->refreshFromCargonizer();

        self::assertCount(2, $methods);
        self::assertNotNull($saved);
        self::assertCount(2, $saved['available_methods']);

        self::assertSame('42|P1', $methods[0]['key']);
        self::assertSame('42', $methods[0]['agreement_id']);
        self::assertSame('Bedriftsavtale', $methods[0]['agreement_name']);
        self::assertSame('Hovedavtale', $methods[0]['agreement_description']);
        self::assertSame('AGR-42', $methods[0]['agreement_number']);
        self::assertSame('7', $methods[0]['carrier_id']);
        self::assertSame('Bring', $methods[0]['carrier_name']);
        self::assertSame('P1', $methods[0]['product_id']);
        self::assertSame('Pakke til hentested', $methods[0]['product_name']);
        self::assertSame([['service_id' => 'S1', 'service_name' => 'Varsling']], $methods[0]['services']);
        self::assertSame('Bring - Bedriftsavtale - Pakke til hentested', $methods[0]['title']);

        self::assertSame('manual|norgespakke', $methods[1]['key']);
        self::assertTrue($methods[1]['is_manual']);
        self::assertSame('Posten - Manuell - Norgespakke', $methods[1]['title']);
    }
}
