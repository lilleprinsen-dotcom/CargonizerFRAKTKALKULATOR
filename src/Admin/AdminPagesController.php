<?php

namespace Lilleprinsen\Cargonizer\Admin;

use Lilleprinsen\Cargonizer\API\CargonizerClient;
use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;
use Lilleprinsen\Cargonizer\Infrastructure\SettingsService;

final class AdminPagesController
{
    private SettingsService $settings;
    private ShippingMethodRegistry $shippingRegistry;
    private CargonizerClient $client;

    public function __construct(SettingsService $settings, ShippingMethodRegistry $shippingRegistry, CargonizerClient $client)
    {
        $this->settings = $settings;
        $this->shippingRegistry = $shippingRegistry;
        $this->client = $client;
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

    public function handleConnectionTest(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('lp_cargonizer_test_connection');

        $result = $this->client->testConnection();

        set_transient('lp_carg_connection_test', $result, 5 * MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg(['page' => 'lp-cargonizer', 'diagnostics' => '1'], admin_url('admin.php')));
        exit;
    }

    public function renderPage(): void
    {
        $settings = $this->settings->getSettings();
        $methods = $this->shippingRegistry->all();
        $diagnostics = $this->client->getDiagnostics();
        $connectionTest = get_transient('lp_carg_connection_test');

        include __DIR__ . '/../../templates/admin-settings.php';
    }
}
