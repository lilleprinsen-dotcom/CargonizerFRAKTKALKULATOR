<?php
/**
 * Integration test for WooCommerce shipping method registration.
 *
 * @package Lilleprinsen\Cargonizer\Tests\Integration
 */

namespace Lilleprinsen\Cargonizer\Tests\Integration;

use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;
use Lilleprinsen\Cargonizer\Shipping\WooShippingIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Verifies shipping method registration integration behavior.
 */
final class WooShippingRegistrationTest extends TestCase {
	/**
	 * Merges dynamic Cargonizer methods with existing WooCommerce methods.
	 */
	public function testRegisterMethodsMergesRegistryMap(): void {
		$registry = $this->createMock( ShippingMethodRegistry::class );
		$registry->method( 'getMethodClassMap' )->willReturn( [ 'lp_cargonizer_1_2' => 'MethodClass' ] );

		$integration = new WooShippingIntegration( $registry );
		$methods     = $integration->registerMethods( [ 'flat_rate' => 'WC_Shipping_Flat_Rate' ] );

		self::assertSame( 'MethodClass', $methods['lp_cargonizer_1_2'] );
		self::assertSame( 'WC_Shipping_Flat_Rate', $methods['flat_rate'] );
	}
}
