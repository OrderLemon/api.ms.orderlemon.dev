<?php

declare(strict_types=1);

namespace Plugins\Users\Services;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\UnauthorizedException;

/**
 * Asks login.ms (owner of the device sessions) whose session a device token is.
 *
 * Function (this service's function_map, service: login.ms):
 *   resolve_session  POST /auth/session/resolve  {token, company_id} → {phonenumber}
 */
final class LoginService
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    /** The caller's phonenumber; 401 when login.ms has no such session. */
    public function phonenumberFor(string $token, int $companyId): string
    {
        try {
            $data = $this->serviceClient->call('resolve_session', [], [
                'token' => $token,
                'company_id' => $companyId,
            ]);
        } catch (ServiceException $e) {
            if (in_array($e->statusCode(), [401, 403, 404], true)) {
                throw new UnauthorizedException('Not a device session');
            }
            throw $e;
        }

        $phonenumber = $data['phonenumber'] ?? null;
        if (!is_string($phonenumber) || $phonenumber === '') {
            throw new ServiceException('Session service returned no phonenumber');
        }

        return $phonenumber;
    }
}
