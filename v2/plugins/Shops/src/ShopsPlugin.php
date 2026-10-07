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
use Pmsrapi\V2\Support\ShopContext;

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
        $router->get('/company/{company_id}', static fn(Request $request, array $params): Response
            => $container->get(ShopsController::class)->listByCompany($params['company_id']));

        $router->get('/{shop_id}', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(ShopsController::class)->getShop($params['shop_id']),
        ));

        $router->patch('/{shop_id}/general', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(ShopsController::class)->updateGeneral($request, $params['shop_id']),
        ));

        $router->get('/{shop_id}/settings', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(ShopsController::class)->getSettings($params['shop_id']),
        ));

        $router->patch('/{shop_id}/settings', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(ShopsController::class)->updateSettings($request, $params['shop_id']),
        ));

        $router->get('/{shop_id}/calendar', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(ShopsController::class)->getCalendar($params['shop_id']),
        ));

        $router->patch('/{shop_id}/calendar', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(ShopsController::class)->updateCalendar($request, $params['shop_id']),
        ));
    }
}
