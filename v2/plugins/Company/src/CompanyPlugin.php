<?php

declare(strict_types=1);

namespace Plugins\Company;

use Plugins\Company\Controllers\CompanyController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Support\ShopContext;

final class CompanyPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            CompanyController::class,
            static fn(Container $container): CompanyController => new CompanyController(
                $container->get(ServiceClient::class),
                $container->get(Logger::class)
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/{company_id}/legal', 
            static fn(Request $request, array $params): Response
                => $container->get(CompanyController::class)->getLegal($params["company_id"]),
        );
 
        $router->put('/{company_id}/legal', 
            static fn(Request $request, array $params): Response
                => $container->get(CompanyController::class)->updateLegal($request, $params["company_id"]),
        );
    }
}
