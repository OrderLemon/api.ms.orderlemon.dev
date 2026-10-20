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

    public function list(Request $request): Response
    {
        return $this->forward('users_roles_list', $request, $request->body);
    }

    public function togglePermission(Request $request): Response
    {
        return $this->forward('users_roles_permissions_update', $request, $request->body);
    }

    public function userRole(Request $request): Response
    {
        return $this->forward('users_roles_get', $request, $request->body);
    }

    public function assign(Request $request): Response
    {
        return $this->forward('users_roles_assign', $request, $request->body);
    }

    public function create(Request $request): Response
    {
        return $this->forward('users_roles_create', $request, $request->body, created: true);
    }

    public function rename(Request $request): Response
    {
        return $this->forward('users_roles_rename', $request, $request->body);
    }

    public function delete(Request $request): Response
    {
        return $this->forward('users_roles_delete', $request, $request->body);
    }

    private function forward(string $function, Request $request, array $body, bool $created = false): Response
    {
        $result = $this->users->call($function, $this->identity->payload($request, $body));

        return $created ? Response::created($result['data']) : Response::ok($result['data'], $result['meta']);
    }
}
