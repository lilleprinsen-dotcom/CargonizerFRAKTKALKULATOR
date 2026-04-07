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



    public function testRefreshFromCargonizerParsesOfficialHyphenatedTransportAgreementXml(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<transport-agreements>
  <transport-agreement>
    <identifier>99</identifier>
    <description>Avtale fra docs</description>
    <number>AGR-99</number>
    <carrier>
      <identifier>301</identifier>
      <name>PostNord</name>
    </carrier>
    <products>
      <product>
        <identifier>PROD-1</identifier>
        <name>Hjemlevering</name>
        <services>
          <service>
            <identifier>SRV-1</identifier>
            <name>Kvittering</name>
          </service>
        </services>
      </product>
    </products>
  </transport-agreement>
</transport-agreements>
XML;

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getSettings')->willReturn(['available_methods' => []]);
        $settings->expects(self::once())->method('save');

        $client = $this->createMock(CargonizerClient::class);
        $client->method('fetchTransportAgreements')->willReturn(['raw' => $xml]);
        $calculator = $this->createMock(RateCalculator::class);
        $registry = new ShippingMethodRegistry($settings, $client, $client, $calculator);

        $methods = $registry->refreshFromCargonizer();

        self::assertCount(2, $methods);
        self::assertSame('99|PROD-1', $methods[0]['key']);
        self::assertSame('99', $methods[0]['agreement_id']);
        self::assertSame('Avtale fra docs', $methods[0]['agreement_name']);
        self::assertSame('Avtale fra docs', $methods[0]['agreement_description']);
        self::assertSame('AGR-99', $methods[0]['agreement_number']);
        self::assertSame('301', $methods[0]['carrier_id']);
        self::assertSame('PostNord', $methods[0]['carrier_name']);
        self::assertSame('PROD-1', $methods[0]['product_id']);
        self::assertSame('Hjemlevering', $methods[0]['product_name']);
        self::assertSame([['service_id' => 'SRV-1', 'service_name' => 'Kvittering']], $methods[0]['services']);
        self::assertSame('manual|norgespakke', $methods[1]['key']);
    }

    public function testRefreshFromCargonizerPrefersDocumentedIdentifierOverLegacyId(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<transport-agreements>
  <transport-agreement>
    <identifier>DOC-99</identifier>
    <id>LEGACY-99</id>
    <description>Avtale</description>
    <carrier>
      <identifier>CARRIER-DOC</identifier>
      <id>CARRIER-LEGACY</id>
      <name>PostNord</name>
    </carrier>
    <products>
      <product>
        <identifier>PROD-DOC</identifier>
        <id>PROD-LEGACY</id>
        <name>Hjemlevering</name>
        <services>
          <service>
            <identifier>SRV-DOC</identifier>
            <id>SRV-LEGACY</id>
            <name>Kvittering</name>
          </service>
        </services>
      </product>
    </products>
  </transport-agreement>
</transport-agreements>
XML;

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getSettings')->willReturn(['available_methods' => []]);
        $settings->expects(self::once())->method('save');

        $client = $this->createMock(CargonizerClient::class);
        $client->method('fetchTransportAgreements')->willReturn(['raw' => $xml]);
        $calculator = $this->createMock(RateCalculator::class);
        $registry = new ShippingMethodRegistry($settings, $client, $client, $calculator);

        $methods = $registry->refreshFromCargonizer();

        self::assertSame('DOC-99|PROD-DOC', $methods[0]['key']);
        self::assertSame('DOC-99', $methods[0]['agreement_id']);
        self::assertSame('CARRIER-DOC', $methods[0]['carrier_id']);
        self::assertSame('PROD-DOC', $methods[0]['product_id']);
        self::assertSame('SRV-DOC', $methods[0]['services'][0]['service_id']);
    }

    public function testGetServicepartnerOptionsSupportsHyphenatedNodeAndPrefersIdentifier(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<service-partners>
  <service-partner>
    <identifier>SP-DOC</identifier>
    <id>SP-LEGACY</id>
    <name>Partner A</name>
  </service-partner>
</service-partners>
XML;

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getSettings')->willReturn([]);

        $client = $this->createMock(CargonizerClient::class);
        $client->method('fetchServicePartners')->willReturn(['raw' => $xml]);
        $calculator = $this->createMock(RateCalculator::class);
        $registry = new ShippingMethodRegistry($settings, $client, $client, $calculator);

        $options = $registry->getServicepartnerOptions(
            ['carrier_id' => '301', 'product_id' => 'PROD-1', 'agreement_id' => 'AGR-1'],
            ['country' => 'NO', 'postcode' => '0150']
        );

        self::assertCount(1, $options);
        self::assertSame('SP-DOC', $options[0]['id']);
        self::assertSame('Partner A', $options[0]['name']);
    }

    public function testResolveAdminEstimateCalculatesManualNorgespakkeAndManualHandlingDebug(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getSettings')->willReturn([
            'method_pricing' => [
                'lp_cargonizer_manualnorgespakke' => [
                    'price_source' => 'manual_norgespakke',
                    'vat_percent' => 25,
                    'manual_norgespakke_include_handling' => 1,
                ],
            ],
        ]);

        $client = $this->createMock(CargonizerClient::class);
        $client->expects(self::never())->method('estimateConsignmentCost');
        $calculator = $this->createMock(RateCalculator::class);
        $registry = new ShippingMethodRegistry($settings, $client, $client, $calculator);

        $result = $registry->resolveAdminEstimate(
            [
                'method_id' => 'lp_cargonizer_manualnorgespakke',
                'key' => 'manual|norgespakke',
                'carrier_name' => 'Posten',
                'title' => 'Posten - Manuell - Norgespakke',
            ],
            [
                'colli' => [
                    ['weight' => 8, 'length' => 100, 'width' => 40, 'height' => 30],
                    ['weight' => 24, 'length' => 121, 'width' => 20, 'height' => 20],
                    ['weight' => 35, 'length' => 70, 'width' => 70, 'height' => 20],
                ],
            ],
            []
        );

        self::assertSame(1211.5, $result['rate']);
        self::assertSame(641.2, $result['estimate_debug']['manual_norgespakke']['base_price_ex_vat']);
        self::assertSame(2, $result['estimate_debug']['manual_norgespakke']['handling_package_count']);
        self::assertSame(328.0, $result['estimate_debug']['manual_norgespakke']['handling_fee_ex_vat']);
        self::assertSame('any_side_over_120cm', $result['estimate_debug']['manual_norgespakke']['package_debug'][1]['manual_handling_reason']);
        self::assertSame('at_least_two_sides_over_60cm', $result['estimate_debug']['manual_norgespakke']['package_debug'][2]['manual_handling_reason']);
        self::assertSame(969.2, $result['estimate_debug']['calculation']['list_price_including_fees']);
        self::assertSame(242.3, $result['estimate_debug']['calculation']['vat_amount']);
        self::assertSame(969.2, $result['estimate_debug']['calculation']['final_ex_vat_price']);
    }

    public function testResolveAdminEstimateAddsManualHandlingFeeForBringMethods(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getSettings')->willReturn([
            'method_pricing' => [
                'lp_cargonizer_bringtest' => [
                    'price_source' => 'estimated',
                    'vat_percent' => 25,
                    'handling_fee' => 10,
                ],
            ],
        ]);

        $client = $this->createMock(CargonizerClient::class);
        $client->method('estimateConsignmentCost')->willReturn([
            'prices' => [
                'estimated_cost' => 100,
            ],
            'requirements' => [],
            'errors' => [],
        ]);
        $calculator = $this->createMock(RateCalculator::class);
        $registry = new ShippingMethodRegistry($settings, $client, $client, $calculator);

        $result = $registry->resolveAdminEstimate(
            [
                'method_id' => 'lp_cargonizer_bringtest',
                'carrier_name' => 'Bring',
                'title' => 'Bring test',
            ],
            [
                'colli' => [
                    ['weight' => 5, 'length' => 130, 'width' => 20, 'height' => 10],
                ],
            ],
            []
        );

        self::assertSame(330.0, $result['rate']);
        self::assertSame(10.0, $result['estimate_debug']['calculation']['handling_fee']);
        self::assertSame(164.0, $result['estimate_debug']['calculation']['manual_handling_fee']);
        self::assertSame(1, $result['estimate_debug']['calculation']['manual_handling_package_count']);
    }

    public function testResolveAdminEstimateUsesHyphenatedGrossAmountWhenGrossIsConfigured(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getSettings')->willReturn([
            'method_pricing' => [
                'lp_cargonizer_hyphen' => [
                    'price_source' => 'gross',
                    'vat_percent' => 0,
                    'handling_fee' => 0,
                ],
            ],
        ]);
        $settings->method('getStaticFallbackRates')->willReturn([]);

        $client = $this->createMock(CargonizerClient::class);
        $client->method('estimateConsignmentCost')->willReturn([
            'prices' => [
                'gross-amount' => 199.9,
            ],
            'requirements' => [],
            'errors' => [],
        ]);
        $calculator = $this->createMock(RateCalculator::class);
        $registry = new ShippingMethodRegistry($settings, $client, $client, $calculator);

        $result = $registry->resolveAdminEstimate(
            [
                'method_id' => 'lp_cargonizer_hyphen',
                'carrier_name' => 'Bring',
                'title' => 'Hyphen gross',
            ],
            ['colli' => [['weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10]]],
            []
        );

        self::assertSame('gross', $result['estimate_debug']['selected_source']['source']);
        self::assertSame(199.9, $result['estimate_debug']['selected_source']['value']);
        self::assertSame(199.9, $result['estimate_debug']['price_fields']['gross']);
    }

    public function testResolveAdminEstimateFallsBackFromConfiguredGrossToNetAlias(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getSettings')->willReturn([
            'method_pricing' => [
                'lp_cargonizer_fallback' => [
                    'price_source' => 'gross',
                    'vat_percent' => 0,
                    'handling_fee' => 0,
                ],
            ],
        ]);
        $settings->method('getStaticFallbackRates')->willReturn([]);

        $client = $this->createMock(CargonizerClient::class);
        $client->method('estimateConsignmentCost')->willReturn([
            'prices' => [
                'net-amount' => 88.8,
            ],
            'requirements' => [],
            'errors' => [],
        ]);
        $calculator = $this->createMock(RateCalculator::class);
        $registry = new ShippingMethodRegistry($settings, $client, $client, $calculator);

        $result = $registry->resolveAdminEstimate(
            [
                'method_id' => 'lp_cargonizer_fallback',
                'carrier_name' => 'Bring',
                'title' => 'Fallback order',
            ],
            ['colli' => [['weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10]]],
            []
        );

        self::assertSame('net', $result['estimate_debug']['selected_source']['source']);
        self::assertSame('configured_source_unavailable', $result['estimate_debug']['selected_source']['fallback_reason']);
        self::assertSame(88.8, $result['estimate_debug']['price_fields']['net']);
    }
}
