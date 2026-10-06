<?php

declare(strict_types=1);

namespace Plugins\Auth\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

/**
 * Proxies user sign-in (Apple, Google) to login.ms, which verifies the provider's token
 * and matches the email to users.email, plus the public provider config the frontend
 * loads on page load.
 *
 * Uses ServiceClient::stream() rather than call(): call() drops the error body on 4xx,
 * and the frontend needs login.ms's error code (no_account, email_ambiguous, …) to tell
 * the user what went wrong.
 */
final class SignInController
{
    /** login.ms error code => status returned to the browser. Anything else becomes 502. */
    private const array STATUS_BY_ERROR_CODE = [
        'validation_failed' => 422,
        'invalid_token' => 401,
        'apple_invalid_code' => 401,
        'account_disabled' => 403,
        'no_account' => 404,
        'email_ambiguous' => 409,
        'rate_limited' => 429,
    ];

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

    public function config(): Response
    {
        return Response::ok((object) $this->call('auth_config', []));
    }

    /** POST /v2/auth/apple  {code, nonce, client_id?} */
    public function apple(Request $request): Response
    {
        $body = $request->body;

        // Forward only the known fields, never the raw body.
        $payload = [
            'code' => $this->requireString($body, 'code', 4096),
            'nonce' => $this->requireString($body, 'nonce', 4096),
        ];
        if (isset($body['client_id'])) {
            $payload['client_id'] = $this->requireString($body, 'client_id', 255);
        }

        return Response::ok($this->call('auth_apple_login', $payload));
    }

    /** POST /v2/auth/google  {id_token, nonce} */
    public function google(Request $request): Response
    {
        $body = $request->body;

        return Response::ok($this->call('auth_google_login', [
            'id_token' => $this->requireString($body, 'id_token', 8192),
            'nonce' => $this->requireString($body, 'nonce', 4096),
        ]));
    }

    /**
     * Calls login.ms and returns its `data`, or throws its error with the right status.
     *
     * @param array<string, string> $payload
     * @return array<string, mixed>
     */
    private function call(string $function, array $payload): array
    {
        $envelope = null;
        foreach ($this->serviceClient->stream($function, [], $payload) as $record) {
            $envelope = $record;
            break; // exactly one record expected: the whole response body.
        }

        if (!is_array($envelope)) {
            $this->logger->error('Empty or non-object response from login.ms', ['function' => $function]);

            throw new ServiceException('Sign-in service returned no response');
        }

        if (!($envelope['success'] ?? false)) {
            $this->throwFor($function, is_array($envelope['error'] ?? null) ? $envelope['error'] : []);
        }

        return is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    }

    /**
     * @param array<string, mixed> $error
     */
    private function throwFor(string $function, array $error): never
    {
        $code = is_string($error['code'] ?? null) ? $error['code'] : 'service_error';
        $status = self::STATUS_BY_ERROR_CODE[$code] ?? null;

        if ($status === null) {
            // A login.ms or configuration problem (bad service token, missing provider config, …):
            // log the real reason, show the browser a generic failure.
            $this->logger->error('Sign-in failed in login.ms', ['function' => $function, 'error' => $error]);

            throw new ServiceException('Sign-in is temporarily unavailable');
        }

        $message = is_string($error['message'] ?? null) ? $error['message'] : 'Sign-in failed';
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        throw new ApiException($message, $status, $code, $details);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requireString(array $body, string $field, int $maxLength): string
    {
        $value = $body[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException([$field => "{$field} is required"]);
        }
        if (strlen($value) > $maxLength) {
            throw new ValidationException([$field => "{$field} is too long"]);
        }
        return trim($value);
    }
}
