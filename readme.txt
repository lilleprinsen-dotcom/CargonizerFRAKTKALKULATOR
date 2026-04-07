=== Lilleprinsen Cargonizer Connector ===
Contributors: lilleprinsen
Tags: woocommerce, shipping, cargonizer
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.0.0
WC tested up to: 10.2.0

WooCommerce Cargonizer integration with modular architecture.

== Extension hooks ==

The plugin now exposes domain-oriented extension points to reduce integration coupling:

* `Lilleprinsen\\Cargonizer\\Domain\\Contracts\\RateProviderInterface`
* `Lilleprinsen\\Cargonizer\\Domain\\Contracts\\PriceModifierInterface`
* `Lilleprinsen\\Cargonizer\\Domain\\Contracts\\PickupPointProviderInterface`
* Stable DTOs in `Lilleprinsen\\Cargonizer\\Domain\\DTO\\*` (`RateQuoteRequest`, `RateQuote`, `PickupPointRequest`, `PickupPoint`).

Use these WordPress hooks for third-party customization:

* `do_action( 'lp_cargonizer_before_remote_quote_fetch', array $payload, string $correlationId, CargonizerClient $client )`
  * Fires just before the remote quote HTTP request is executed.
* `do_action( 'lp_cargonizer_after_remote_quote_fetch', ?RateQuote $quote, array $payload, string $correlationId, CargonizerClient $client, ?WP_Error $error )`
  * Fires after the remote quote request completes (success or failure).
* `apply_filters( 'lp_cargonizer_rate_post_processing', float $calculatedRate, float $rawRate, array $methodConfig, array $package, RateQuoteRequest $request, ?RateQuote $quote )`
  * Allows final post-processing of calculated shipping rates.
* `apply_filters( 'lp_cargonizer_before_rate_publish', array $rateDefinition, array $methodConfig, array $package, CargonizerShippingMethod $method )`
  * Lets integrations modify WooCommerce rate payload before it is published.
* `do_action( 'lp_cargonizer_before_rate_publish_action', array $rateDefinition, array $methodConfig, array $package, CargonizerShippingMethod $method )`
  * Action counterpart for side effects/observability before publishing a rate.
