<?php

declare(strict_types=1);

namespace Plugins\Exports;

use Plugins\Exports\Controllers\ExportsController;
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
 *   POST /v2/exports/{shop_id}/{type}    body {"format": "csv"|"xlsx"} (default csv)
 *
 * Forwarded to dataport.ms as function_map entry exports_create; returns
 * {type, format, url, file_name, rows}.
 */
final class ExportsPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            ExportsController::class,
            static fn(Container $container): ExportsController => new ExportsController(
                $container->get(ServiceClient::class),
                $container->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->post(
            '/{shop_id}/{type}',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(ExportsController::class)->createExport(
                        $request,
                        $params['shop_id'],
                        $params['type'],
                    ),
            ),
        );
    }
}
