<?php

declare(strict_types=1);

namespace Plugins\Billing;

use Plugins\Billing\Controllers\BillingController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Support\ShopContext;

final class BillingPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            BillingController::class,
            static fn(Container $container): BillingController => new BillingController(
                $container->get(ServiceClient::class),
                $container->get(Logger::class)
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/{shop_id}/plan', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(BillingController::class)->plan(),
        ));

        $router->get('/{shop_id}/invoices', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(BillingController::class)->invoices(),
        ));

        $router->get('/{shop_id}/invoice/{invoice_id}', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(BillingController::class)->invoice($params["invoice_id"]),
        ));

        $router->get('/{shop_id}/invoice/{invoice_id}/pdf', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(BillingController::class)->invoicePdf($params["invoice_id"]),
        ));

        $router->get('/{shop_id}/payment-method', ShopContext::wrap(
            static fn(Request $request, array $params): Response
                => $container->get(BillingController::class)->paymentMethod($params["invoice_id"]),
        ));
    }
}
