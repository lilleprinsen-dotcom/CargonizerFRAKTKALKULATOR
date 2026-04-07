<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('lp_cargonizer_settings');
