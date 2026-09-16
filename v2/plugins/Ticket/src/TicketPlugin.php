<?php

declare(strict_types=1);

namespace Plugins\Ticket;

use Plugins\Ticket\Controllers\TicketController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;

final class TicketPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            TicketController::class,
            static fn(Container $container): TicketController => new TicketController(
                $container->get(ServiceClient::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/{shop_id}/{order_id}', static fn(Request $request, array $params): Response
            => $container->get(TicketController::class)->getData($params['shop_id'], $params['order_id']));
    }
}
