<?php

declare(strict_types=1);

namespace Plugins\Users;

use Plugins\Users\Controllers\UsersController;
use Plugins\Users\Services\LoginService;
use Plugins\Users\Services\UsersService;
use Plugins\Users\Support\DeviceIdentity;
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
use Pmsrapi\V2\Support\ShopContext;
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
            LoginService::class,
            static fn(Container $container): LoginService => new LoginService(
                $container->get(ServiceClient::class),
            ),
        );

        $registrar->singleton(
            DeviceIdentity::class,
            static fn(Container $container): DeviceIdentity => new DeviceIdentity(
                $container->get(TokenStore::class),
            ),
        );

        $registrar->singleton(
            UsersController::class,
            static fn(Container $container): UsersController => new UsersController(
                $container->get(UsersService::class),
                $container->get(LoginService::class),
                $container->get(DeviceIdentity::class),
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

        // Fixed paths first: the router takes the first match, so /me must not reach /{shop_id}.
        $router->get('/me', static fn(Request $r, array $p): Response => $users()->me($r));
        $router->put('/me', static fn(Request $r, array $p): Response => $users()->updateMe($r));

        // Another user: the shop is the first path segment (like the other plugins), the target
        // user is `phonenumber` in the body, never in the URL.
        $router->get('/{shop_id}/list', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $users()->list($r, $p['shop_id']),
        ));
        $router->post('/{shop_id}/get', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $users()->get($r, $p['shop_id']),
        ));
        $router->put('/{shop_id}', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $users()->update($r, $p['shop_id']),
        ));
        $router->post('/{shop_id}/invite', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $users()->invite($r, $p['shop_id']),
        ));
        $router->post('/{shop_id}/remove', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $users()->remove($r, $p['shop_id']),
        ));
        $router->post('/{shop_id}/resend-invite', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $users()->resendInvite($r, $p['shop_id']),
        ));
    }
}
