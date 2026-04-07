<?php
/**
 * Plugin Name: Lilleprinsen Cargonizer Connector
 * Description: WooCommerce Cargonizer integration with modular architecture.
 * Version: 2.0.0
 * Author: Lilleprinsen
 */

if (!defined('ABSPATH')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/src/Infrastructure/Autoloader.php';
    Lilleprinsen\Cargonizer\Infrastructure\Autoloader::register();
}

$container = new Lilleprinsen\Cargonizer\Infrastructure\ServiceContainer();
$plugin = new Lilleprinsen\Cargonizer\Infrastructure\Plugin($container);
$plugin->boot();
