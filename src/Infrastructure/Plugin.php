<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

use Lilleprinsen\Cargonizer\Admin\AdminOrderController;
use Lilleprinsen\Cargonizer\Admin\AdminPagesController;
use Lilleprinsen\Cargonizer\API\AjaxController;
use Lilleprinsen\Cargonizer\API\RestController;
use Lilleprinsen\Cargonizer\Checkout\CheckoutService;
use Lilleprinsen\Cargonizer\Compatibility\CheckoutHookAdapter;
use Lilleprinsen\Cargonizer\Compatibility\CompatibilityBridge;
use Lilleprinsen\Cargonizer\Compatibility\OrderHooksAdapter;
use Lilleprinsen\Cargonizer\Compatibility\WooCommerceVersionGuard;
use Lilleprinsen\Cargonizer\Shipping\RateCalculator;
use Lilleprinsen\Cargonizer\Shipping\ShippingMethodRegistry;
use Lilleprinsen\Cargonizer\Shipping\WooShippingIntegration;

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
        $this->container->set(RateCalculator::class, fn (): RateCalculator => new RateCalculator());
        $this->container->set(ShippingMethodRegistry::class, fn (ServiceContainer $c): ShippingMethodRegistry => new ShippingMethodRegistry($c->get(SettingsService::class), $c->get(CargonizerClient::class), $c->get(RateCalculator::class)));
        $this->container->set(WooShippingIntegration::class, fn (ServiceContainer $c): WooShippingIntegration => new WooShippingIntegration($c->get(ShippingMethodRegistry::class)));
        $this->container->set(CompatibilityBridge::class, fn (): CompatibilityBridge => new CompatibilityBridge());
        $this->container->set(WooCommerceVersionGuard::class, fn (): WooCommerceVersionGuard => new WooCommerceVersionGuard());
        $this->container->set(CheckoutService::class, fn (): CheckoutService => new CheckoutService());
        $this->container->set(CheckoutHookAdapter::class, fn (ServiceContainer $c): CheckoutHookAdapter => new CheckoutHookAdapter($c->get(CheckoutService::class)));
        $this->container->set(AdminPagesController::class, fn (ServiceContainer $c): AdminPagesController => new AdminPagesController($c->get(SettingsService::class), $c->get(ShippingMethodRegistry::class)));
        $this->container->set(AdminOrderController::class, fn (): AdminOrderController => new AdminOrderController());
        $this->container->set(OrderHooksAdapter::class, fn (ServiceContainer $c): OrderHooksAdapter => new OrderHooksAdapter($c->get(AdminOrderController::class)));
        $this->container->set(AjaxController::class, fn (ServiceContainer $c): AjaxController => new AjaxController($c->get(ShippingMethodRegistry::class)));
        $this->container->set(RestController::class, fn (ServiceContainer $c): RestController => new RestController($c->get(ShippingMethodRegistry::class)));
        $this->container->set(HooksRegistrar::class, fn (ServiceContainer $c): HooksRegistrar => new HooksRegistrar(
            $c->get(AdminPagesController::class),
            $c->get(AdminOrderController::class),
            $c->get(AjaxController::class),
            $c->get(RestController::class),
            $c->get(WooShippingIntegration::class),
            $c->get(CheckoutHookAdapter::class),
            $c->get(CompatibilityBridge::class),
            $c->get(OrderHooksAdapter::class),
            $c->get(WooCommerceVersionGuard::class),
            $c->get(ShippingMethodRegistry::class)
        ));
    }
}
