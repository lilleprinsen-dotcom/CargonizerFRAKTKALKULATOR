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
}
