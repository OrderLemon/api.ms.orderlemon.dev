<?php

declare(strict_types=1);

namespace Plugins\Cart;

use Plugins\Cart\Controllers\CartController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Support\ShopContext;

/**
 * Cart gateway — exposes cart_update/cart_checkout/cart_get (see
 * .claude/cart.md) at /v2/cart/{shop_id}/update|checkout|get, forwarding to
 * the cart microservice via ServiceClient. Same pattern as Orders/Categories.
 *
 * shop_id is a route param (not a body field) so this can use the core
 * ShopContext::wrap() as-is, same as every other plugin.
 */
final class CartPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            CartController::class,
            static fn(Container $container): CartController => new CartController(
                $container->get(ServiceClient::class),
                $container->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->post('/{shop_id}/update', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(CartController::class)->update($request),
        ));

        $router->post('/{shop_id}/checkout', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(CartController::class)->checkout($request),
        ));

        $router->get('/{shop_id}/{phone}', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(CartController::class)->get($request, $params["phone"]),
        ));
    }
}
