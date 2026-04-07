<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

use Lilleprinsen\Cargonizer\Admin\AdminPagesController;
use Lilleprinsen\Cargonizer\API\AjaxController;
use Lilleprinsen\Cargonizer\API\RestController;

final class HooksRegistrar
{
    private AdminPagesController $adminPages;
    private AjaxController $ajaxController;
    private RestController $restController;

    public function __construct(
        AdminPagesController $adminPages,
        AjaxController $ajaxController,
        RestController $restController
    ) {
        $this->adminPages = $adminPages;
        $this->ajaxController = $ajaxController;
        $this->restController = $restController;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this->adminPages, 'registerMenu']);
        add_action('admin_init', [$this->adminPages, 'registerSettings']);
        add_action('admin_post_lp_cargonizer_save', [$this->adminPages, 'handleSave']);

        add_action('wp_ajax_lp_cargonizer_fetch_methods', [$this->ajaxController, 'fetchMethods']);

        add_action('rest_api_init', [$this->restController, 'registerRoutes']);
    }
}
