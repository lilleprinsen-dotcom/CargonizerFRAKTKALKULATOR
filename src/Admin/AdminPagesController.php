<?php

namespace Lilleprinsen\Cargonizer\Admin;

use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;

final class AdminPagesController
{
    private SettingsService $settings;
    private ShippingMethodRegistry $shippingRegistry;

    public function __construct(SettingsService $settings, ShippingMethodRegistry $shippingRegistry)
    {
        $this->settings = $settings;
        $this->shippingRegistry = $shippingRegistry;
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            'Cargonizer',
            'Cargonizer',
            'manage_woocommerce',
            'lp-cargonizer',
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting('lp_cargonizer_group', 'lp_cargonizer_settings');
    }

    public function handleSave(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('lp_cargonizer_save');

        $settings = isset($_POST['lp_cargonizer_settings']) && is_array($_POST['lp_cargonizer_settings'])
            ? wp_unslash($_POST['lp_cargonizer_settings'])
            : [];

        $this->settings->save($settings);

        wp_safe_redirect(add_query_arg(['page' => 'lp-cargonizer', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function renderPage(): void
    {
        $settings = $this->settings->getSettings();
        $methods = $this->shippingRegistry->all();

        include __DIR__ . '/../../templates/admin-settings.php';
    }
}
