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
use Pmsrapi\V2\Support\ShopContext;

/**
 * Roles plugin: forwards to users.ms, which owns roles and permissions. Uses the Users
 * plugin's users.ms client and device identity (dependency in plugin.json).
 *
 * Routes (auto-prefixed with the slug "roles"). Like the other plugins, the shop is the
 * first path segment; phonenumbers stay in the body.
 *
 *   GET    /v2/roles/registry                        every permission, by category
 *   GET    /v2/roles/me                              the caller's shops, role and permissions in each
 *   GET    /v2/roles/{shop_id}                       the shop's roles with their toggles
 *   POST   /v2/roles/{shop_id}                       {name, description?, permissions}: a custom role
 *   PUT    /v2/roles/{shop_id}/{role_id}             {name, description?}: rename a custom role
 *   DELETE /v2/roles/{shop_id}/{role_id}             delete a custom role nobody has
 *   PUT    /v2/roles/{shop_id}/{role_id}/permissions {feature, enabled}: switch one toggle
 *   POST   /v2/roles/{shop_id}/get                   {phonenumber}: a user's role in the shop
 *   PUT    /v2/roles/{shop_id}/assign                {phonenumber, role_id}: give a role, or null
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

        // Fixed paths first: the router takes the first match, so /me must not reach /{shop_id}.
        $router->get('/registry', static fn(Request $r, array $p): Response => $roles()->registry($r));
        $router->get('/me', static fn(Request $r, array $p): Response => $roles()->me($r));

        $router->get('/{shop_id}', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $roles()->list($r, $p['shop_id']),
        ));
        $router->post('/{shop_id}', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $roles()->create($r, $p['shop_id']),
        ));
        $router->post('/{shop_id}/get', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $roles()->userRole($r, $p['shop_id']),
        ));
        $router->put('/{shop_id}/assign', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $roles()->assign($r, $p['shop_id']),
        ));
        $router->put('/{shop_id}/{role_id}/permissions', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $roles()->togglePermission($r, $p['shop_id'], $p['role_id']),
        ));
        $router->put('/{shop_id}/{role_id}', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $roles()->rename($r, $p['shop_id'], $p['role_id']),
        ));
        $router->delete('/{shop_id}/{role_id}', ShopContext::wrap(
            static fn(Request $r, array $p): Response => $roles()->delete($r, $p['shop_id'], $p['role_id']),
        ));
    }
}
