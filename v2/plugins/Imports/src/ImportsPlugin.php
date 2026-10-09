<?php

declare(strict_types=1);

namespace Plugins\Imports;

use Plugins\Imports\Controllers\ImportsController;
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
 *   POST /v2/imports/{shop_id}/{type}               body {"file": "<base64>", "file_name": "x.xlsx", "dry_run": true}
 *   GET  /v2/imports/{shop_id}/{type}/{import_id}   status of a queued import
 *
 * Forwarded to dataport.ms as function_map entries imports_create and
 * imports_get. A dry run returns what the import would do ({create, delete});
 * otherwise the import is queued and 201 {import_id, ...} is returned. Row
 * errors in the file come back as 422 with {row, column, message} errors.
 */
final class ImportsPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            ImportsController::class,
            static fn(Container $container): ImportsController => new ImportsController(
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
                    => $container->get(ImportsController::class)->createImport(
                        $request,
                        $params['shop_id'],
                        $params['type'],
                    ),
            ),
        );

        $router->get(
            '/{shop_id}/{type}/{import_id}',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(ImportsController::class)->getImport(
                        $params['shop_id'],
                        $params['type'],
                        $params['import_id'],
                    ),
            ),
        );
    }
}
