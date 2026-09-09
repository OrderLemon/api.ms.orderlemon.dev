<?php

declare(strict_types=1);

namespace Plugins\Shops;

use Plugins\Shops\Controllers\ShopsController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;

final class ShopsPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            ShopsController::class,
            static fn(Container $container): ShopsController => new ShopsController(
                $container->get(ServiceClient::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/phone/{phone}', static fn(Request $request, array $params): Response
            => $container->get(ShopsController::class)->getByPhone($params['phone']));

        $router->get('/{id}', static fn(Request $request, array $params): Response
            => $container->get(ShopsController::class)->getById($params['id']));
    }
}
