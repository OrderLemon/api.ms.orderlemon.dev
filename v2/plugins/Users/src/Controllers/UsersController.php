<?php

declare(strict_types=1);

namespace Plugins\Users\Controllers;

use Plugins\Users\Services\LoginService;
use Plugins\Users\Services\UsersService;
use Plugins\Users\Support\DeviceIdentity;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class UsersController
{
    public function __construct(
        private readonly UsersService $users,
        private readonly LoginService $login,
        private readonly DeviceIdentity $identity,
    ) {}

    public function me(Request $request): Response
    {
        return $this->forward('users_get', $request, ['phonenumber' => $this->callerPhonenumber($request)]);
    }

    public function updateMe(Request $request): Response
    {
        $body = $request->body;
        $body['phonenumber'] = $this->callerPhonenumber($request); // always the caller

        return $this->forward('users_update', $request, $body);
    }

    public function list(Request $request, string $shopId): Response
    {
        $payload = ['shop_id' => $shopId];
        foreach (['role_id', 'page', 'per_page', 'enabled'] as $key) {
            if (isset($request->query[$key])) {
                $payload[$key] = $request->query[$key];
            }
        }

        return $this->forward('users_list', $request, $payload);
    }

    // The path's shop_id wins over a shop_id in the body: `+` keeps the left side.
    public function get(Request $request, string $shopId): Response
    {
        return $this->forward('users_get', $request, ['shop_id' => $shopId] + $request->body);
    }

    public function update(Request $request, string $shopId): Response
    {
        return $this->forward('users_update', $request, ['shop_id' => $shopId] + $request->body);
    }

    public function invite(Request $request, string $shopId): Response
    {
        return $this->forward('users_invite', $request, ['shop_id' => $shopId] + $request->body, created: true);
    }

    public function remove(Request $request, string $shopId): Response
    {
        return $this->forward('users_remove', $request, ['shop_id' => $shopId] + $request->body);
    }

    public function resendInvite(Request $request, string $shopId): Response
    {
        return $this->forward('users_resend_invite', $request, ['shop_id' => $shopId] + $request->body);
    }

    private function forward(string $function, Request $request, array $body, bool $created = false): Response
    {
        $result = $this->users->call($function, $this->identity->payload($request, $body));

        return $created ? Response::created($result['data']) : Response::ok($result['data'], $result['meta']);
    }

    private function callerPhonenumber(Request $request): string
    {
        $identity = $this->identity->of($request);

        return $this->login->phonenumberFor($identity['device_uuid'], $identity['company_id']);
    }
}
