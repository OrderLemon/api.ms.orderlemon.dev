<?php

declare(strict_types=1);

namespace Plugins\Orders;

use Plugins\Orders\Controllers\OrdersController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;

final class OrdersPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            OrdersController::class,
            static fn(Container $container): OrdersController => new OrdersController(
                $container->get(ServiceClient::class),
                $container->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        // Identifiers in the path; phone numbers in the JSON body, never the URL.

        // ?status=new|preparing|ready|done filters by status; omitted = all.
        $router->get('/{shop_id}', static fn(Request $request, array $params): Response
            => $container->get(OrdersController::class)->listOrders($request, $params['shop_id']));

        $router->post('/{shop_id}/client/usual', static fn(Request $request, array $params): Response
            => $container->get(OrdersController::class)->usualForClient($request, $params['shop_id']));

        $router->post('/{shop_id}/client/reorder', static fn(Request $request, array $params): Response
            => $container->get(OrdersController::class)->reorder($request, $params['shop_id']));

        // Registered after every literal /{shop_id}/... route: the router is first-match.
        $router->get('/{shop_id}/{id}', static fn(Request $request, array $params): Response
            => $container->get(OrdersController::class)->getOrder($params['shop_id'], $params['id']));

        $router->patch('/{shop_id}/{id}', static fn(Request $request, array $params): Response
            => $container->get(OrdersController::class)->updateOrder($request, $params['shop_id'], $params['id']));
    }
}
