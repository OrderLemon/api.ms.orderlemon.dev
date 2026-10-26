<?php

declare(strict_types=1);

namespace Plugins\Happy_hour;

use Plugins\Happy_hour\Controllers\HappyHourController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Support\ShopContext;

// The happy_hour.ms routes (spec 10.2) under /v2/happy_hour/…
final class Happy_hourPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            HappyHourController::class,
            static fn(Container $c): HappyHourController => new HappyHourController(
                $c->get(ServiceClient::class),
                $c->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get(
            '/{shop_id}/settings',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(HappyHourController::class)->getSettings($params['shop_id']),
            ),
        );

        $router->put(
            '/{shop_id}/settings',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(HappyHourController::class)->updateSettings($request, $params['shop_id']),
            ),
        );

        $router->post(
            '/{shop_id}/start',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(HappyHourController::class)->start($params['shop_id']),
            ),
        );

        $router->post(
            '/{shop_id}/stop',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(HappyHourController::class)->stop($params['shop_id']),
            ),
        );

        $router->get(
            '/{shop_id}/runs',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(HappyHourController::class)->getRuns($params['shop_id']),
            ),
        );

        $router->get(
            '/{shop_id}/runs/{happy_hour_id}',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(HappyHourController::class)->getRun($params['shop_id'], $params['happy_hour_id']),
            ),
        );

        $router->put(
            '/{shop_id}/runs/{happy_hour_id}/products',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(HappyHourController::class)->addRunProduct(
                        $request,
                        $params['shop_id'],
                        $params['happy_hour_id'],
                    ),
            ),
        );
    }
}
