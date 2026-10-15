<?php

declare(strict_types=1);

namespace Plugins\Users\Controllers;

use Plugins\Users\Services\LoginService;
use Plugins\Users\Services\UsersService;
use Pmsrapi\V2\Exception\UnauthorizedException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Security\TokenStore;

final class UsersController
{
    private const array IDENTITY_KEYS = ['device_uuid', 'company_id'];

    public function __construct(
        private readonly UsersService $users,
        private readonly LoginService $login,
        private readonly TokenStore $tokens,
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

    public function list(Request $request): Response
    {
        $payload = [];
        foreach (['page', 'per_page', 'enabled'] as $key) {
            if (isset($request->query[$key])) {
                $payload[$key] = $request->query[$key];
            }
        }

        return $this->forward('users_list', $request, $payload);
    }

    public function get(Request $request): Response
    {
        return $this->forward('users_get', $request, $request->body);
    }

    public function update(Request $request): Response
    {
        return $this->forward('users_update', $request, $request->body);
    }

    public function invite(Request $request): Response
    {
        return $this->forward('users_invite', $request, $request->body, created: true);
    }

    public function remove(Request $request): Response
    {
        return $this->forward('users_remove', $request, $request->body);
    }

    public function resendInvite(Request $request): Response
    {
        return $this->forward('users_resend_invite', $request, $request->body);
    }

    public function myPermissions(Request $request): Response
    {
        return $this->forward('users_permissions_get', $request, ['phonenumber' => $this->callerPhonenumber($request)]);
    }

    public function permissions(Request $request): Response
    {
        return $this->forward('users_permissions_get', $request, $request->body);
    }

    public function updatePermissions(Request $request): Response
    {
        $payload = array_diff_key($request->body, array_flip(self::IDENTITY_KEYS)) + $this->identity($request);
        $data = $this->users->call('users_permissions_update', $payload)['data'];

        // These are {feature: …} maps. Decoding users.ms's JSON turned an empty
        // one into [], so make them objects again for the app.
        foreach (['added', 'removed', 'skipped'] as $key) {
            if (is_array($data) && isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = (object) $data[$key];
            }
        }

        return Response::ok($data);
    }

    private function forward(string $function, Request $request, array $body, bool $created = false): Response
    {
        $payload = array_diff_key($body, array_flip(self::IDENTITY_KEYS)) + $this->identity($request);

        $result = $this->users->call($function, $payload);

        return $created ? Response::created($result['data']) : Response::ok($result['data'], $result['meta']);
    }

    private function callerPhonenumber(Request $request): string
    {
        $identity = $this->identity($request);

        return $this->login->phonenumberFor($identity['device_uuid'], $identity['company_id']);
    }

    private function identity(Request $request): array
    {
        $token = (string) $request->bearerToken();
        $companyId = $this->tokens->claims($token)['company_id'] ?? null;
        if (!is_int($companyId) || $companyId < 1) {
            // The static service token, or a token that isn't a device session.
            throw new UnauthorizedException('Not a device session');
        }

        return ['device_uuid' => $token, 'company_id' => $companyId];
    }
}
