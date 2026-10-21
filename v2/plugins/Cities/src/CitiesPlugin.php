<?php

declare(strict_types=1);

namespace Plugins\Cities;

use Plugins\Cities\Controllers\CitiesController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Support\ShopContext;

final class CitiesPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            CitiesController::class,
            static fn(Container $container): CitiesController => new CitiesController(
                $container->get(ServiceClient::class),
                $container->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/{shop_id}', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(CitiesController::class)->getCities($request, $params['shop_id']),
        ));
    }
}
