<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

use Lilleprinsen\Cargonizer\Admin\AdminOrderController;
use Lilleprinsen\Cargonizer\Admin\AdminPagesController;
use Lilleprinsen\Cargonizer\API\AjaxController;
use Lilleprinsen\Cargonizer\API\RestController;
use Lilleprinsen\Cargonizer\Checkout\CheckoutService;
use Lilleprinsen\Cargonizer\Compatibility\CompatibilityBridge;
use Lilleprinsen\Cargonizer\Shipping\WooShippingIntegration;

final class HooksRegistrar
{
    private AdminPagesController $adminPages;
    private AdminOrderController $adminOrderController;
    private AjaxController $ajaxController;
    private RestController $restController;
    private WooShippingIntegration $wooShipping;
    private CheckoutService $checkoutService;
    private CompatibilityBridge $compatibilityBridge;

    public function __construct(
        AdminPagesController $adminPages,
        AdminOrderController $adminOrderController,
        AjaxController $ajaxController,
        RestController $restController,
        WooShippingIntegration $wooShipping,
        CheckoutService $checkoutService,
        CompatibilityBridge $compatibilityBridge
    ) {
        $this->adminPages = $adminPages;
        $this->adminOrderController = $adminOrderController;
        $this->ajaxController = $ajaxController;
        $this->restController = $restController;
        $this->wooShipping = $wooShipping;
        $this->checkoutService = $checkoutService;
        $this->compatibilityBridge = $compatibilityBridge;
    }

    public function register(): void
    {
        add_action('before_woocommerce_init', [$this->compatibilityBridge, 'declareWooCommerceFeaturesCompatibility']);

        add_action('admin_menu', [$this->adminPages, 'registerMenu']);
        add_action('admin_init', [$this->adminPages, 'registerSettings']);
        add_action('admin_post_lp_cargonizer_save', [$this->adminPages, 'handleSave']);

        add_action('wp_ajax_lp_cargonizer_fetch_methods', [$this->ajaxController, 'fetchMethods']);

        add_action('rest_api_init', [$this->restController, 'registerRoutes']);

        add_action('woocommerce_shipping_init', [$this->wooShipping, 'shippingInit']);
        add_filter('woocommerce_shipping_methods', [$this->wooShipping, 'registerMethods']);

        add_action('woocommerce_checkout_create_order', [$this->checkoutService, 'persistOrderMetadata'], 20, 2);

        add_filter('manage_edit-shop_order_columns', [$this->adminOrderController, 'registerLegacyOrderColumns']);
        add_action('manage_shop_order_posts_custom_column', [$this->adminOrderController, 'renderLegacyOrderColumn'], 10, 2);

        add_filter('manage_woocommerce_page_wc-orders_columns', [$this->adminOrderController, 'registerHposOrderColumns']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this->adminOrderController, 'renderHposOrderColumn'], 10, 2);

        add_action('woocommerce_admin_order_data_after_shipping_address', [$this->adminOrderController, 'renderOrderShipmentPanel']);
    }
}
