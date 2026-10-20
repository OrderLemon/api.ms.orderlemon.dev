<?php

declare(strict_types=1);

namespace Plugins\Roles\Controllers;

use Plugins\Users\Services\UsersService;
use Plugins\Users\Support\DeviceIdentity;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class RolesController
{
    public function __construct(
        private readonly UsersService $users,
        private readonly DeviceIdentity $identity,
    ) {}

    public function registry(Request $request): Response
    {
        return $this->forward('users_registry', $request, []);
    }

    public function me(Request $request): Response
    {
        return $this->forward('users_roles_me', $request, []);
    }

    public function list(Request $request, string $shopId): Response
    {
        return $this->forward('users_roles_list', $request, ['shop_id' => $shopId]);
    }

    public function create(Request $request, string $shopId): Response
    {
        return $this->forward('users_roles_create', $request, ['shop_id' => $shopId] + $request->body, created: true);
    }

    public function rename(Request $request, string $shopId, string $roleId): Response
    {
        return $this->forward('users_roles_rename', $request, ['shop_id' => $shopId, 'role_id' => $roleId] + $request->body);
    }

    public function delete(Request $request, string $shopId, string $roleId): Response
    {
        return $this->forward('users_roles_delete', $request, ['shop_id' => $shopId, 'role_id' => $roleId]);
    }

    public function togglePermission(Request $request, string $shopId, string $roleId): Response
    {
        return $this->forward(
            'users_roles_permissions_update',
            $request,
            ['shop_id' => $shopId, 'role_id' => $roleId] + $request->body,
        );
    }

    public function userRole(Request $request, string $shopId): Response
    {
        return $this->forward('users_roles_get', $request, ['shop_id' => $shopId] + $request->body);
    }

    public function assign(Request $request, string $shopId): Response
    {
        return $this->forward('users_roles_assign', $request, ['shop_id' => $shopId] + $request->body);
    }

    // The path's shop_id (and role_id) win over the same keys in a body: `+` keeps the left side.
    private function forward(string $function, Request $request, array $body, bool $created = false): Response
    {
        $result = $this->users->call($function, $this->identity->payload($request, $body));

        return $created ? Response::created($result['data']) : Response::ok($result['data'], $result['meta']);
    }
}
