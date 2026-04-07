<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('lp_cargonizer_settings');

delete_option('lp_cargonizer_db_version');
