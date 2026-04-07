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
        register_setting('lp_cargonizer_group', 'lp_cargonizer_settings', [
            'type' => 'array',
            'sanitize_callback' => function ($value): array {
                $settings = is_array($value) ? $value : [];

                return $this->settings->sanitizeSettings($settings, $this->settings->getSettings());
            },
            'default' => [],
        ]);
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

        $settings = $this->mergeMethodEnablement($settings);
        $this->settings->save($settings);

        wp_safe_redirect(add_query_arg(['page' => 'lp-cargonizer', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handleRefreshMethods(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('lp_cargonizer_refresh_methods');
        $methods = $this->shippingRegistry->refreshFromCargonizer();

        set_transient('lp_carg_refresh_result', [
            'count' => count($methods),
            'ok' => $methods !== [],
        ], 5 * MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg(['page' => 'lp-cargonizer', 'refreshed' => '1'], admin_url('admin.php')));
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
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        $settings = $this->settings->getSettings();
        $methods = $this->shippingRegistry->all();
        $diagnostics = $this->client->getDiagnostics();
        $connectionTest = get_transient('lp_carg_connection_test');
        $refreshResult = get_transient('lp_carg_refresh_result');
        $maskedCredentials = [
            'api_key' => $this->maskSecret((string) ($settings['api_key'] ?? '')),
            'sender_id' => $this->maskSecret((string) ($settings['sender_id'] ?? '')),
        ];

        include __DIR__ . '/../../templates/admin-settings.php';
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function mergeMethodEnablement(array $settings): array
    {
        $available = $this->shippingRegistry->all();
        $enabledMap = [];
        $enabled = isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? $settings['enabled_methods'] : [];
        foreach ($enabled as $methodId) {
            $enabledMap[sanitize_key((string) $methodId)] = true;
        }

        $settings['available_methods'] = array_map(static function ($method) use ($enabledMap) {
            if (!is_array($method)) {
                return [];
            }

            $methodId = sanitize_key((string) ($method['method_id'] ?? ''));
            $method['enabled'] = isset($enabledMap[$methodId]) ? 'yes' : 'no';

            return $method;
        }, $available);

        return $settings;
    }

    private function maskSecret(string $value): string
    {
        if ($value === '') {
            return '(not set)';
        }

        if (strpos($value, 'enc:v1:') === 0) {
            return '•••••••• (encrypted)';
        }

        if (strlen($value) <= 4) {
            return '••••';
        }

        return str_repeat('•', max(4, strlen($value) - 4)) . substr($value, -4);
    }
}
