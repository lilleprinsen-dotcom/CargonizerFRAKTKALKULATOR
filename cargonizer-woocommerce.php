<?php
/**
 * Plugin Name: Lilleprinsen Cargonizer Connector
 * Description: WooCommerce Cargonizer integration with modular architecture.
 * Version: 2.0.0
 * WC requires at least: 7.0.0
 * WC tested up to: 10.2.0
 * Author: Lilleprinsen
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('LP_CARGONIZER_PLUGIN_FILE')) {
    define('LP_CARGONIZER_PLUGIN_FILE', __FILE__);
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/src/Infrastructure/Autoloader.php';
    Lilleprinsen\Cargonizer\Infrastructure\Autoloader::register();
}

/**
 * Shared plugin service container accessor.
 */
function lp_cargonizer_container(?Lilleprinsen\Cargonizer\Infrastructure\ServiceContainer $container = null): Lilleprinsen\Cargonizer\Infrastructure\ServiceContainer
{
    static $instance = null;

    if ($container !== null) {
        $instance = $container;
    }

    if (!$instance instanceof Lilleprinsen\Cargonizer\Infrastructure\ServiceContainer) {
        $instance = new Lilleprinsen\Cargonizer\Infrastructure\ServiceContainer();
    }

    return $instance;
}

$container = lp_cargonizer_container();
$plugin = new Lilleprinsen\Cargonizer\Infrastructure\Plugin($container);
$plugin->boot();
