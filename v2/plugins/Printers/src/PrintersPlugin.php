<?php

declare(strict_types=1);

namespace Plugins\Printers;

use Plugins\Printers\Controllers\PrintersController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Support\ShopContext;

// The cloudprint.ms printer routes under /v2/printers/…. Linking a new printer to a shop is
// done by OrderLemon, not the panel, so there is no activate route here.
final class PrintersPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            PrintersController::class,
            static fn(Container $c): PrintersController => new PrintersController(
                $c->get(ServiceClient::class),
                $c->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get(
            '/company/{company_id}',
            static fn(Request $request, array $params): Response
                => $container->get(PrintersController::class)->listForCompany($params['company_id']),
        );

        $router->get('/{shop_id}', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(PrintersController::class)->listForShop($params['shop_id']),
        ));

        $router->put('/{shop_id}/{printer_id}', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(PrintersController::class)->update($request, $params['shop_id'], $params['printer_id']),
        ));

        $router->delete('/{shop_id}/{printer_id}', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(PrintersController::class)->remove($params['shop_id'], $params['printer_id']),
        ));

        $router->post('/{shop_id}/{printer_id}/test', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(PrintersController::class)->test($request, $params['shop_id'], $params['printer_id']),
        ));

        $router->post('/{shop_id}/orders/{order_id}/print', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(PrintersController::class)->printOrder($request, $params['shop_id'], $params['order_id']),
        ));
    }
}
