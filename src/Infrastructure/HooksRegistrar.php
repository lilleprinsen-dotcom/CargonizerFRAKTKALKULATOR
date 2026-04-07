<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

use Lilleprinsen\Cargonizer\Admin\AdminOrderController;
use Lilleprinsen\Cargonizer\Admin\AdminPagesController;
use Lilleprinsen\Cargonizer\API\AjaxController;
use Lilleprinsen\Cargonizer\API\RestController;
use Lilleprinsen\Cargonizer\Compatibility\CheckoutHookAdapter;
use Lilleprinsen\Cargonizer\Compatibility\CompatibilityBridge;
use Lilleprinsen\Cargonizer\Compatibility\OrderHooksAdapter;
use Lilleprinsen\Cargonizer\Compatibility\WooCommerceVersionGuard;
use Lilleprinsen\Cargonizer\Shipping\WooShippingIntegration;
use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;

final class HooksRegistrar
{
    private AdminPagesController $adminPages;
    private AdminOrderController $adminOrderController;
    private AjaxController $ajaxController;
    private RestController $restController;
    private WooShippingIntegration $wooShipping;
    private CheckoutHookAdapter $checkoutHookAdapter;
    private CompatibilityBridge $compatibilityBridge;
    private OrderHooksAdapter $orderHooksAdapter;
    private WooCommerceVersionGuard $wooCommerceVersionGuard;
    private ShippingMethodRegistry $shippingMethodRegistry;

    public function __construct(
        AdminPagesController $adminPages,
        AdminOrderController $adminOrderController,
        AjaxController $ajaxController,
        RestController $restController,
        WooShippingIntegration $wooShipping,
        CheckoutHookAdapter $checkoutHookAdapter,
        CompatibilityBridge $compatibilityBridge,
        OrderHooksAdapter $orderHooksAdapter,
        WooCommerceVersionGuard $wooCommerceVersionGuard,
        ShippingMethodRegistry $shippingMethodRegistry
    ) {
        $this->adminPages = $adminPages;
        $this->adminOrderController = $adminOrderController;
        $this->ajaxController = $ajaxController;
        $this->restController = $restController;
        $this->wooShipping = $wooShipping;
        $this->checkoutHookAdapter = $checkoutHookAdapter;
        $this->compatibilityBridge = $compatibilityBridge;
        $this->orderHooksAdapter = $orderHooksAdapter;
        $this->wooCommerceVersionGuard = $wooCommerceVersionGuard;
        $this->shippingMethodRegistry = $shippingMethodRegistry;
    }

    public function register(): void
    {
        add_action('before_woocommerce_init', [$this->compatibilityBridge, 'declareWooCommerceFeaturesCompatibility']);
        $this->wooCommerceVersionGuard->registerAdminNoticeIfIncompatible();

        if (!$this->wooCommerceVersionGuard->isCompatible()) {
            return;
        }

        add_action('admin_menu', [$this->adminPages, 'registerMenu']);
        add_action('admin_init', [$this->adminPages, 'registerSettings']);
        add_action('admin_post_lp_cargonizer_save', [$this->adminPages, 'handleSave']);
        add_action('admin_post_lp_cargonizer_test_connection', [$this->adminPages, 'handleConnectionTest']);
        add_action('admin_post_lp_cargonizer_refresh_methods', [$this->adminPages, 'handleRefreshMethods']);

        add_action('wp_ajax_lp_cargonizer_fetch_methods', [$this->ajaxController, 'fetchMethods']);
        add_action('wp_ajax_lp_cargonizer_get_order_estimate_data', [$this->ajaxController, 'getOrderEstimateData']);
        add_action('wp_ajax_lp_cargonizer_get_shipping_options', [$this->ajaxController, 'getShippingOptions']);
        add_action('wp_ajax_lp_cargonizer_run_bulk_estimate', [$this->ajaxController, 'runBulkEstimate']);
        add_action('wp_ajax_lp_cargonizer_get_servicepartner_options', [$this->ajaxController, 'getServicepartnerOptions']);

        add_action('rest_api_init', [$this->restController, 'registerRoutes']);

        add_action('woocommerce_shipping_init', [$this->wooShipping, 'shippingInit']);
        add_filter('woocommerce_shipping_methods', [$this->wooShipping, 'registerMethods']);

        add_action('woocommerce_checkout_create_order', [$this->checkoutHookAdapter, 'persistOrderMetadataFromHook'], 20, 2);

        $this->orderHooksAdapter->registerOrderColumnHooks();

        add_action('woocommerce_admin_order_data_after_order_details', [$this->adminOrderController, 'renderOrderEstimatorButton']);
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this->adminOrderController, 'renderOrderShipmentPanel']);
        add_action('admin_footer', [$this->adminOrderController, 'renderOrderEstimatorModal']);
        add_action(ShippingMethodRegistry::ACTION_REFRESH_METHODS, [$this->shippingMethodRegistry, 'runRefreshMethodsJob']);
        add_action(ShippingMethodRegistry::ACTION_RUN_BULK_ESTIMATE, [$this->shippingMethodRegistry, 'runBulkEstimateJob']);
    }
}
