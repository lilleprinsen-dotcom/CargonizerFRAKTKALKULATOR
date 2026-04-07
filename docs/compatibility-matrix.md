# WooCommerce Compatibility Matrix

This plugin currently supports WooCommerce **7.0.0+** and is tested through **10.2.0**.

## Runtime compatibility behavior

- If WooCommerce is missing, plugin integrations are not registered and an admin notice is shown.
- If WooCommerce is active but older than 7.0.0, plugin integrations are not registered and an admin notice is shown.
- If WooCommerce is compatible, all integrations register normally.

## Hook/API matrix

| Concern | Hook/API | WooCommerce Version Notes | Primary Path | Fallback/Adapter Path |
|---|---|---|---|---|
| Feature declaration (HPOS compatibility) | `before_woocommerce_init` + `Automattic\\WooCommerce\\Utilities\\FeaturesUtil::declare_compatibility()` | `FeaturesUtil` is unavailable in older releases. | `CompatibilityBridge::declareWooCommerceFeaturesCompatibility()` declares `custom_order_tables`. | If `FeaturesUtil` class is missing, method returns without side effects. |
| Checkout order persistence signature | `woocommerce_checkout_create_order` | Hook arguments vary by context and may include only order in edge cases. | `CheckoutHookAdapter::persistOrderMetadataFromHook(...$args)` extracts `WC_Order` and checkout payload. | If payload is missing/invalid, adapter supplies safe defaults and no-ops when order object is not valid. |
| Legacy order-list admin columns | `manage_edit-shop_order_columns` and `manage_shop_order_posts_custom_column` | Present with legacy post-based order list. | `OrderHooksAdapter::registerOrderColumnHooks()` wires legacy callbacks directly. | No-op at runtime when list table is not in use. |
| HPOS order-list admin columns | `manage_woocommerce_page_wc-orders_columns` and `manage_woocommerce_page_wc-orders_custom_column` | Callback second argument can be object/id based on internals/version differences. | `OrderHooksAdapter::renderHposOrderColumnCompat($column, $orderOrId)` normalizes to string order id and delegates. | If ID cannot be resolved, renderer receives empty ID and safely exits. |
| Shipping integration registration | `woocommerce_shipping_init` + `woocommerce_shipping_methods` | Shipping base class availability varies by load order. | `WooShippingIntegration` checks for `WC_Shipping_Method` and registers methods via registry. | If shipping base class is unavailable at hook runtime, shipping init safely returns. |
| REST routes | `register_rest_route()` | Available in supported WordPress/WooCommerce combinations. | `RestController::registerRoutes()` registers authenticated endpoint. | Global runtime guard avoids calling WooCommerce-dependent stack when WooCommerce missing/too old. |

## Adapter classes

- `src/Compatibility/WooCommerceVersionGuard.php`
- `src/Compatibility/CheckoutHookAdapter.php`
- `src/Compatibility/OrderHooksAdapter.php`
- `src/Compatibility/CompatibilityBridge.php`

These adapters isolate hook signature variations and ensure fail-safe behavior across supported versions.
