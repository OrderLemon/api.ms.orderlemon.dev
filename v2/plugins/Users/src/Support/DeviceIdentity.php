<?php

declare(strict_types=1);

namespace Plugins\Users\Support;

use Pmsrapi\V2\Exception\UnauthorizedException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Security\TokenStore;

/**
 * The identity users.ms is called with: the device token as `device_uuid` and its
 * TokenStore `company_id` claim. Always set by the gateway, never taken from a client.
 */
final class DeviceIdentity
{
    private const array KEYS = ['device_uuid', 'company_id'];

    public function __construct(
        private readonly TokenStore $tokens,
    ) {}

    /** @return array{device_uuid: string, company_id: int} */
    public function of(Request $request): array
    {
        $token = (string) $request->bearerToken();
        $companyId = $this->tokens->claims($token)['company_id'] ?? null;
        if (!is_int($companyId) || $companyId < 1) {
            // The static service token, or a token that isn't a device session.
            throw new UnauthorizedException('Not a device session');
        }

        return ['device_uuid' => $token, 'company_id' => $companyId];
    }

    /**
     * A body for users.ms: the client's fields, with the identity keys always the gateway's.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function payload(Request $request, array $body): array
    {
        return array_diff_key($body, array_flip(self::KEYS)) + $this->of($request);
    }
}
