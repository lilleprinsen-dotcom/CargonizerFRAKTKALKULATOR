<?php

require_once __DIR__ . '/../src/Infrastructure/Autoloader.php';
\Lilleprinsen\Cargonizer\Infrastructure\Autoloader::register();

if (!defined('LP_CARGONIZER_PLUGIN_FILE')) {
    define('LP_CARGONIZER_PLUGIN_FILE', __FILE__);
}
if (!defined('LP_CARGONIZER_VERSION')) {
    define('LP_CARGONIZER_VERSION', '2.1.0');
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

$GLOBALS['__wp_options'] = [];
$GLOBALS['__wp_filters'] = [];
$GLOBALS['__wp_transients'] = [];
$GLOBALS['__wc_session'] = [];

function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $value)); }
function esc_url_raw($value) { return filter_var((string) $value, FILTER_SANITIZE_URL) ?: ''; }

function __($value, $domain = null) { return $value; }
function esc_html__($value, $domain = null) { return $value; }
function esc_html($value) { return (string) $value; }
function wp_kses_post($value) { return (string) $value; }

function add_action($tag, $fn, $priority = 10, $accepted_args = 1) { $GLOBALS['__wp_filters'][$tag][] = $fn; return true; }
function add_filter($tag, $fn, $priority = 10, $accepted_args = 1) { $GLOBALS['__wp_filters'][$tag][] = $fn; return true; }
function apply_filters($tag, $value) {
    $args = func_get_args();
    foreach ($GLOBALS['__wp_filters'][$tag] ?? [] as $fn) {
        $value = $fn(...array_slice($args, 1));
        $args[1] = $value;
    }
    return $value;
}
function do_action($tag) {
    $args = array_slice(func_get_args(), 1);
    foreach ($GLOBALS['__wp_filters'][$tag] ?? [] as $fn) { $fn(...$args); }
}
function get_option($k, $d = false) { return $GLOBALS['__wp_options'][$k] ?? $d; }
function update_option($k, $v, $autoload = null) { $GLOBALS['__wp_options'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['__wp_transients'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['__wp_transients'][$k] = $v; return true; }
function wp_generate_uuid4() { return 'uuid-test'; }

class WC_Order {
    public array $meta = [];
    public function update_meta_data($key, $value): void { $this->meta[$key] = $value; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function get_id(): int { return 42; }
}

class WC_Shipping_Method {}

class WCSess {
    public function get(string $key) { return $GLOBALS['__wc_session'][$key] ?? null; }
}
class WCMock { public $session; public function __construct(){ $this->session = new WCSess(); }}
function WC() { static $w; if (!$w) { $w = new WCMock(); } return $w; }
