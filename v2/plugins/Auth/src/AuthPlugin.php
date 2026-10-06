<?php

declare(strict_types=1);

namespace Plugins\Auth;

use Plugins\Auth\Controllers\AppleController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\Logger;

final class AuthPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            AppleController::class,
            static fn(Container $container): AppleController => new AppleController(
                $container->get(ServiceClient::class),
                $container->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->post('/apple', static fn(Request $request): Response
            => $container->get(AppleController::class)->login($request));
    }
}
