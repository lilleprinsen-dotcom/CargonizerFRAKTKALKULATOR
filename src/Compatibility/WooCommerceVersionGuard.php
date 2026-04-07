<?php

namespace Lilleprinsen\Cargonizer\Compatibility;

final class WooCommerceVersionGuard
{
    public const MINIMUM_SUPPORTED_VERSION = '7.0.0';
    public const TESTED_MAXIMUM_VERSION = '10.2.0';

    public function isCompatible(): bool
    {
        if (!defined('WC_VERSION')) {
            return false;
        }

        return version_compare((string) WC_VERSION, self::MINIMUM_SUPPORTED_VERSION, '>=');
    }

    public function registerAdminNoticeIfIncompatible(): void
    {
        if ($this->isCompatible()) {
            return;
        }

        add_action('admin_notices', [$this, 'renderIncompatibleWooCommerceNotice']);
    }

    public function renderIncompatibleWooCommerceNotice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $installedVersion = defined('WC_VERSION') ? (string) WC_VERSION : null;

        $message = $installedVersion === null
            ? sprintf(
                'Lilleprinsen Cargonizer Connector requires WooCommerce %s or newer. WooCommerce is currently not active.',
                self::MINIMUM_SUPPORTED_VERSION
            )
            : sprintf(
                'Lilleprinsen Cargonizer Connector requires WooCommerce %s or newer. Installed WooCommerce version is %s.',
                self::MINIMUM_SUPPORTED_VERSION,
                $installedVersion
            );

        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }
}
