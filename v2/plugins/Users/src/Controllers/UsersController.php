<?php

declare(strict_types=1);

namespace Plugins\Users\Controllers;

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
        private readonly TokenStore $tokens,
    ) {}

    public function me(Request $request): Response
    {
        return $this->forward('users_get', $request, []);
    }

    public function updateMe(Request $request): Response
    {
        $body = $request->body;
        unset($body['phonenumber']); // always the caller

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

    private function forward(string $function, Request $request, array $body, bool $created = false): Response
    {
        $payload = array_diff_key($body, array_flip(self::IDENTITY_KEYS)) + $this->identity($request);

        $result = $this->users->call($function, $payload);

        return $created ? Response::created($result['data']) : Response::ok($result['data'], $result['meta']);
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
