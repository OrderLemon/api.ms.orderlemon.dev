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
 *   users_update         PATCH /users
 *   users_invite         POST  /users/invite
 *   users_remove         POST  /users/remove
 *   users_resend_invite  POST  /users/resend-invite
 *   users_permissions_get     POST /users/permissions/get
 *   users_permissions_update  PUT  /users/permissions
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
        'rate_limited' => 429,
    ];

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

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
