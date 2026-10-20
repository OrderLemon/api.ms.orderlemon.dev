<?php

declare(strict_types=1);

namespace Plugins\Roles;

use Plugins\Roles\Controllers\RolesController;
use Plugins\Users\Services\UsersService;
use Plugins\Users\Support\DeviceIdentity;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;

/**
 * Roles plugin: forwards to users.ms, which owns roles and permissions. Uses the Users
 * plugin's users.ms client and device identity (dependency in plugin.json).
 *
 * Routes (auto-prefixed with the slug "roles"):
 *
 *   GET    /v2/roles/registry      every permission, by category
 *   GET    /v2/roles/me            the caller's shops, role and permissions in each
 *   POST   /v2/roles/list          {shop_id}: the shop's roles with their toggles
 *   PUT    /v2/roles/permissions   {shop_id, role_id, feature, enabled}: switch one toggle
 *   POST   /v2/roles/get           {shop_id, phonenumber}: a user's role in the shop
 *   PUT    /v2/roles/assign        {shop_id, phonenumber, role_id}: give a role, or null
 */
final class RolesPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            RolesController::class,
            static fn(Container $container): RolesController => new RolesController(
                $container->get(UsersService::class),
                $container->get(DeviceIdentity::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $roles = static fn(): RolesController => $container->get(RolesController::class);

        $router->get('/registry', static fn(Request $r, array $p): Response => $roles()->registry($r));
        $router->get('/me', static fn(Request $r, array $p): Response => $roles()->me($r));

        // Everything else is per shop: the body names it with `shop_id`.
        $router->post('/list', static fn(Request $r, array $p): Response => $roles()->list($r));
        $router->put('/permissions', static fn(Request $r, array $p): Response => $roles()->togglePermission($r));
        $router->post('/get', static fn(Request $r, array $p): Response => $roles()->userRole($r));
        $router->put('/assign', static fn(Request $r, array $p): Response => $roles()->assign($r));
    }
}
