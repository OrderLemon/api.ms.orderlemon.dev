<?php

declare(strict_types=1);

namespace Plugins\Products;

use Plugins\Products\Controllers\ProductsController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\ShopContext;

final class ProductsPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            ProductsController::class,
            static fn(Container $container): ProductsController => new ProductsController(
                $container->get(ServiceClient::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get(
            '/{shop_id}',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(ProductsController::class)->getProducts($params['shop_id']),
            ),
        );
    }
}