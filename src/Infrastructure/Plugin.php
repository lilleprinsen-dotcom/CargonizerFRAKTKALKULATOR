<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

use Lilleprinsen\Cargonizer\Admin\AdminPagesController;
use Lilleprinsen\Cargonizer\API\AjaxController;
use Lilleprinsen\Cargonizer\API\RestController;
use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;

final class Plugin
{
    private ServiceContainer $container;

    public function __construct(ServiceContainer $container)
    {
        $this->container = $container;
        $this->registerServices();
    }

    public function boot(): void
    {
        /** @var HooksRegistrar $hooks */
        $hooks = $this->container->get(HooksRegistrar::class);
        $hooks->register();
    }

    private function registerServices(): void
    {
        $this->container->set(SettingsRepository::class, fn (): SettingsRepository => new SettingsRepository());
        $this->container->set(SettingsService::class, fn (ServiceContainer $c): SettingsService => new SettingsService($c->get(SettingsRepository::class)));
        $this->container->set(CargonizerClient::class, fn (ServiceContainer $c): CargonizerClient => new CargonizerClient($c->get(SettingsService::class)));
        $this->container->set(ShippingMethodRegistry::class, fn (ServiceContainer $c): ShippingMethodRegistry => new ShippingMethodRegistry($c->get(SettingsService::class), $c->get(CargonizerClient::class)));
        $this->container->set(AdminPagesController::class, fn (ServiceContainer $c): AdminPagesController => new AdminPagesController($c->get(SettingsService::class), $c->get(ShippingMethodRegistry::class)));
        $this->container->set(AjaxController::class, fn (ServiceContainer $c): AjaxController => new AjaxController($c->get(ShippingMethodRegistry::class)));
        $this->container->set(RestController::class, fn (ServiceContainer $c): RestController => new RestController($c->get(ShippingMethodRegistry::class)));
        $this->container->set(HooksRegistrar::class, fn (ServiceContainer $c): HooksRegistrar => new HooksRegistrar(
            $c->get(AdminPagesController::class),
            $c->get(AjaxController::class),
            $c->get(RestController::class)
        ));
    }
}
