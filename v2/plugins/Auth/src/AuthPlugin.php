<?php

declare(strict_types=1);

namespace Plugins\Auth;

use Plugins\Auth\Controllers\SignInController;
use Plugins\Auth\Guest\GuestAuthHandler;
use Plugins\Auth\Guest\GuestTokens;
use Pmsrapi\V2\Cache\RateLimiter;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Security\TokenStore;
use Pmsrapi\V2\Support\Logger;

/**
 * User sign-in. Sign-in itself happens on the public entry point /auth.php (repo root),
 * protected by short-lived guest tokens; see GuestAuthHandler. Only logout is a /v2 route,
 * because it's called with a device token the core already accepts.
 */
final class AuthPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            SignInController::class,
            static fn(Container $container): SignInController => new SignInController(
                $container->get(ServiceClient::class),
                $container->get(TokenStore::class),
                $container->get(Logger::class),
            ),
        );

        $registrar->singleton(
            GuestTokens::class,
            static fn(Container $container): GuestTokens => new GuestTokens(
                $container->get(Config::class),
            ),
        );

        $registrar->singleton(
            GuestAuthHandler::class,
            static fn(Container $container): GuestAuthHandler => new GuestAuthHandler(
                $container->get(GuestTokens::class),
                $container->get(SignInController::class),
                $container->get(RateLimiter::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->post('/logout', static fn(Request $request): Response
            => $container->get(SignInController::class)->logout($request));
    }
}
