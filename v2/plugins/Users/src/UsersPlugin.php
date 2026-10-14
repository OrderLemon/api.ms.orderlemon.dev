<?php

declare(strict_types=1);

namespace Plugins\Users;

use Plugins\Users\Controllers\UsersController;
use Plugins\Users\Services\UsersService;
use Plugins\Users\Webhooks\UsersWebhookHandler;
use Pmsrapi\V2\Cache\RateLimiter;
use Pmsrapi\V2\Cache\RedisClient;
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

final class UsersPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            UsersService::class,
            static fn(Container $container): UsersService => new UsersService(
                $container->get(ServiceClient::class),
                $container->get(Logger::class),
            ),
        );

        $registrar->singleton(
            UsersController::class,
            static fn(Container $container): UsersController => new UsersController(
                $container->get(UsersService::class),
                $container->get(TokenStore::class),
            ),
        );

        $registrar->singleton(
            UsersWebhookHandler::class,
            static fn(Container $container): UsersWebhookHandler => new UsersWebhookHandler(
                $container->get(Config::class),
                $container->get(RedisClient::class),
                $container->get(TokenStore::class),
                $container->get(RateLimiter::class),
                $container->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $users = static fn(): UsersController => $container->get(UsersController::class);

        $router->get('/me', static fn(Request $r, array $p): Response => $users()->me($r));
        $router->patch('/me', static fn(Request $r, array $p): Response => $users()->updateMe($r));
        $router->get('/list', static fn(Request $r, array $p): Response => $users()->list($r));

        // The target user is `phonenumber` in the body, never in the URL.
        $router->post('/get', static fn(Request $r, array $p): Response => $users()->get($r));
        $router->patch('/', static fn(Request $r, array $p): Response => $users()->update($r));
        $router->post('/invite', static fn(Request $r, array $p): Response => $users()->invite($r));
        $router->post('/remove', static fn(Request $r, array $p): Response => $users()->remove($r));
        $router->post('/resend-invite', static fn(Request $r, array $p): Response => $users()->resendInvite($r));
    }
}
