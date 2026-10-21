<?php

declare(strict_types=1);

namespace Plugins\Payment_methods;

use Plugins\Payment_methods\Controllers\PaymentMethodsController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\ShopContext;

/**
 * Payment methods of a shop, mounted under /payment_methods:
 *
 *   GET /v2/payment_methods/{shop_id}
 *   GET /v2/payment_methods/{shop_id}/checkout
 *   PUT /v2/payment_methods/{shop_id}/{payment_method_id}
 *
 * function_map entries it needs:
 *
 *   "payment_methods_list":      { "service": "payway.ms", "method": "GET", "path": "/payment_methods/{shop_id}" }
 *   "payment_methods_checkout":  { "service": "payway.ms", "method": "GET", "path": "/payment_methods/{shop_id}/checkout" }
 *   "payment_methods_configure": { "service": "payway.ms", "method": "PUT", "path": "/payment_methods/{shop_id}/{payment_method_id}" }
 */
final class Payment_methodsPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            PaymentMethodsController::class,
            static fn(Container $container): PaymentMethodsController => new PaymentMethodsController(
                $container->get(ServiceClient::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/{shop_id}', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(PaymentMethodsController::class)->list($params['shop_id']),
        ));

        $router->get('/{shop_id}/checkout', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(PaymentMethodsController::class)->checkout($params['shop_id']),
        ));

        $router->put('/{shop_id}/{payment_method_id}', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(PaymentMethodsController::class)->configure(
                    $request,
                    $params['shop_id'],
                    $params['payment_method_id'],
                ),
        ));
    }
}
