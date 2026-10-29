<?php

declare(strict_types=1);

namespace Plugins\Logistics;

use Plugins\Auth\Access\EndpointSecurity;
use Plugins\Logistics\Controllers\LogisticsController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\ShopContext;

final class LogisticsPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            LogisticsController::class,
            static fn(Container $container): LogisticsController => new LogisticsController(
                $container->get(ServiceClient::class),
                $container->get(EndpointSecurity::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/{shop_id}', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(LogisticsController::class)->getLogistics($request, $params['shop_id']),
        ));

        $router->put('/{shop_id}', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(LogisticsController::class)->updateLogistics($request, $params['shop_id']),
        ));
    }
}
