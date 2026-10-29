<?php

declare(strict_types=1);

namespace Plugins\Users\Services;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Support\Logger;

/**
 * Calls users.ms and returns its response envelope, or rethrows its error with the right status.
 *
 * Uses ServiceClient::stream() rather than call(): call() drops the error body on 4xx, and the
 * app needs users.ms's error code and validation details. stream() doesn't expose the HTTP
 * status, so the status comes from the error code (STATUS_BY_ERROR_CODE).
 *
 * Functions (this service's function_map, service: users.ms):
 *   users_get            POST  /users/get
 *   users_list           POST  /users/list
 *   users_update         PUT   /users
 *   users_invite         POST  /users/invite
 *   users_remove         POST  /users/remove
 *   users_resend_invite  POST  /users/resend-invite
 *   users_registry                  POST  /users/registry
 *   users_roles_me                  POST  /users/roles/me
 *   users_roles_list                POST  /users/roles/list
 *   users_roles_permissions_update  PUT   /users/roles/permissions
 *   users_roles_get                 POST  /users/roles/get
 *   users_roles_assign              PUT   /users/roles
 *   users_roles_create              POST  /users/roles/create
 *   users_roles_rename              PUT   /users/roles/rename
 *   users_roles_delete              POST  /users/roles/delete
 */
final class UsersService
{
    /** users.ms error code => status returned to the app. Anything else becomes 502. */
    private const array STATUS_BY_ERROR_CODE = [
        'validation_failed' => 422,
        'unauthorized' => 401,
        'forbidden' => 403,
        'user_not_found' => 404,
        'user_exists' => 409,
        'invite_not_pending' => 409,
        'role_locked' => 409,
        'role_name_taken' => 409,
        'role_in_use' => 409,
        'system_role' => 409,
        'shop_lookup_unavailable' => 503,
        'rate_limited' => 429,
    ];

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

    public function getByPhone(string $phoneNumber) : ?array
    {
        
        
        $user = $this->call("users_get",["phonenumber" => $phoneNumber]);
        
        return $user["data"] ?? null;
    }
    
    /**
     * @param array<string, mixed> $payload
     * @return array{data: mixed, meta: ?array<string, mixed>}
     */
    public function call(string $function, array $payload): array
    {
        $envelope = null;
        foreach ($this->serviceClient->stream($function, [], $payload) as $record) {
            $envelope = $record;
            break; // exactly one record expected: the whole response body.
        }

        if (!is_array($envelope) || !array_key_exists('success', $envelope)) {
            $this->logger->error('Empty or non-envelope response from users.ms', ['function' => $function]);

            throw new ServiceException('Users service returned no response');
        }

        if ($envelope['success'] !== true) {
            $this->throwFor($function, is_array($envelope['error'] ?? null) ? $envelope['error'] : []);
        }

        return [
            'data' => $envelope['data'] ?? null,
            'meta' => is_array($envelope['meta'] ?? null) ? $envelope['meta'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $error
     */
    private function throwFor(string $function, array $error): never
    {
        $code = is_string($error['code'] ?? null) ? $error['code'] : 'service_error';
        $status = self::STATUS_BY_ERROR_CODE[$code] ?? null;

        if ($status === null) {
            // A users.ms or configuration problem: log the real reason, show the app a generic failure.
            $this->logger->error('Call to users.ms failed', ['function' => $function, 'error' => $error]);

            throw new ServiceException('Users service is temporarily unavailable');
        }

        $message = is_string($error['message'] ?? null) ? $error['message'] : 'Request failed';
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        throw new ApiException($message, $status, $code, $details);
    }
}
