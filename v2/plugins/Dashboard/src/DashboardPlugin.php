<?php

declare(strict_types=1);

namespace Plugins\Dashboard;

use Plugins\Dashboard\Controllers\DashboardController;
use Plugins\Dashboard\Services\DashboardService;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;

final class DashboardPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            DashboardService::class,
            static fn(Container $container): DashboardService => new DashboardService(
                $container->get(ServiceClient::class),
            ),
        );

        $registrar->singleton(
            DashboardController::class,
            static fn(Container $container): DashboardController => new DashboardController(
                $container->get(DashboardService::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/{shop_id}', static fn(Request $request, array $params): Response
            => $container->get(DashboardController::class)->show($request, $params['shop_id']));
    }
}
