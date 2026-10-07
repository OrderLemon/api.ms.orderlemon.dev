<?php

declare(strict_types=1);

namespace Plugins\Home;

use Plugins\Home\Controllers\HomeController;
use Plugins\Home\Services\HomeService;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\Logger;

/**
 * Dashboard home, mounted under /home:
 *
 *   GET /v2/home/{shop_id}
 *
 * Each owning service decides what its own numbers mean; this plugin only
 * collects them. function_map entries it needs:
 *
 *   "orders_summary":    { "service": "orders.ms",    "method": "GET", "path": "/orders/{shop_id}/summary" }
 *   "products_summary":  { "service": "products.ms",  "method": "GET", "path": "/products/{shop_id}/summary" }
 *   "campaigns_summary": { "service": "campaigns.ms", "method": "GET", "path": "/campaigns/{shop_id}/summary" }
 *   "shop_summary":      { "service": "shop.ms",      "method": "GET", "path": "/shop/{shop_id}/summary" }
 */
final class HomePlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            HomeService::class,
            static fn(Container $container): HomeService => new HomeService(
                $container->get(ServiceClient::class),
                $container->get(Logger::class),
            ),
        );

        $registrar->singleton(
            HomeController::class,
            static fn(Container $container): HomeController => new HomeController(
                $container->get(HomeService::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/{shop_id}', static fn(Request $request, array $params): Response
            => $container->get(HomeController::class)->show($params['shop_id']));
    }
}
