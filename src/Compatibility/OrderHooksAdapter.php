<?php

namespace Lilleprinsen\Cargonizer\Compatibility;

use Lilleprinsen\Cargonizer\Admin\AdminOrderController;

final class OrderHooksAdapter
{
    private AdminOrderController $adminOrderController;

    public function __construct(AdminOrderController $adminOrderController)
    {
        $this->adminOrderController = $adminOrderController;
    }

    public function registerOrderColumnHooks(): void
    {
        add_filter('manage_edit-shop_order_columns', [$this->adminOrderController, 'registerLegacyOrderColumns']);
        add_action('manage_shop_order_posts_custom_column', [$this->adminOrderController, 'renderLegacyOrderColumn'], 10, 2);

        add_filter('manage_woocommerce_page_wc-orders_columns', [$this->adminOrderController, 'registerHposOrderColumns']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'renderHposOrderColumnCompat'], 10, 2);
    }

    /**
     * @param mixed $column
     * @param mixed $orderOrId
     */
    public function renderHposOrderColumnCompat($column, $orderOrId): void
    {
        $orderId = is_scalar($orderOrId) ? (string) $orderOrId : '';

        if ($orderId === '' && is_object($orderOrId) && method_exists($orderOrId, 'get_id')) {
            $id = $orderOrId->get_id();
            $orderId = is_scalar($id) ? (string) $id : '';
        }

        $this->adminOrderController->renderHposOrderColumn((string) $column, $orderId);
    }
}
