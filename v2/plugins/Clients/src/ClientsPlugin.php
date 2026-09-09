<?php

declare(strict_types=1);

namespace Plugins\Clients;

use Plugins\Clients\Controllers\ClientsController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;

final class ClientsPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            ClientsController::class,
            static fn(Container $container): ClientsController => new ClientsController(
                $container->get(ServiceClient::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/{shop_id}/{phonenumber}', static fn(Request $request, array $params): Response
            => $container->get(ClientsController::class)->getInfo($params['shop_id'], $params['phonenumber']));
    }
}
