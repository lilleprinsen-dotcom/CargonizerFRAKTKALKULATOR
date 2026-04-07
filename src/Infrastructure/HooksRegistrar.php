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

    public function __construct(
        AdminPagesController $adminPages,
        AdminOrderController $adminOrderController,
        AjaxController $ajaxController,
        RestController $restController,
        WooShippingIntegration $wooShipping,
        CheckoutHookAdapter $checkoutHookAdapter,
        CompatibilityBridge $compatibilityBridge,
        OrderHooksAdapter $orderHooksAdapter,
        WooCommerceVersionGuard $wooCommerceVersionGuard
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

        add_action('wp_ajax_lp_cargonizer_fetch_methods', [$this->ajaxController, 'fetchMethods']);

        add_action('rest_api_init', [$this->restController, 'registerRoutes']);

        add_action('woocommerce_shipping_init', [$this->wooShipping, 'shippingInit']);
        add_filter('woocommerce_shipping_methods', [$this->wooShipping, 'registerMethods']);

        add_action('woocommerce_checkout_create_order', [$this->checkoutHookAdapter, 'persistOrderMetadataFromHook'], 20, 2);

        $this->orderHooksAdapter->registerOrderColumnHooks();

        add_action('woocommerce_admin_order_data_after_shipping_address', [$this->adminOrderController, 'renderOrderShipmentPanel']);
    }
}
