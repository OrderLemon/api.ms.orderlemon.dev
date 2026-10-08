<?php

declare(strict_types=1);

namespace Plugins\Auth;

use Plugins\Auth\Controllers\SignInController;
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
            SignInController::class,
            static fn(Container $container): SignInController => new SignInController(
                $container->get(ServiceClient::class),
                $container->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/config', static fn(Request $request): Response
            => $container->get(SignInController::class)->config());

        $router->post('/apple', static fn(Request $request): Response
            => $container->get(SignInController::class)->apple($request));

        $router->post('/google', static fn(Request $request): Response
            => $container->get(SignInController::class)->google($request));

        $router->post('/password', static fn(Request $request): Response
            => $container->get(SignInController::class)->password($request));
    }
}
