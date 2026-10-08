<?php

declare(strict_types=1);

namespace Plugins\Auth\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\UnauthorizedException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Security\TokenStore;
use Pmsrapi\V2\Support\Logger;

/**
 * Proxies user sign-in (Apple, Google, email + password) to login.ms, which verifies the
 * credentials, matches the email to users.email and creates a device session; plus the public
 * provider config the frontend loads on page load, and logout.
 *
 * Sessions: login.ms returns a device token (its hash is stored in devices_{company_id}).
 * This gateway registers the token in the core TokenStore WITHOUT expiry, so the core
 * AuthMiddleware accepts it as `Authorization: Bearer <token>` on every later request.
 * TokenStore::claims() then gives the company_id; the user is the devices_{company_id} row whose
 * device_uuid is the token's SHA-256.
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
        'invalid_credentials' => 401,
        'password_login_unavailable' => 503,
        'account_disabled' => 403,
        'no_company' => 403,
        'no_account' => 404,
        'email_ambiguous' => 409,
        'rate_limited' => 429,
    ];

    /** Optional device details forwarded with every sign-in => max length. */
    private const array DEVICE_FIELDS = ['os' => 100, 'app_ver' => 8, 'language' => 2, 'one_signal_id' => 50];

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly TokenStore $tokens,
        private readonly Logger $logger,
    ) {}

    /** GET /v2/auth/config  → { apple?: {client_id, redirect_uri}, google?: {client_id} } */
    public function config(): Response
    {
        // Always a JSON object, even with no providers ({} not []), so the frontend can read config.apple.
        return Response::ok((object) $this->call('auth_config', []));
    }

    /** POST /v2/auth/apple  {code, nonce, client_id?, os?, app_ver?, language?, one_signal_id?} */
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

        return $this->signIn('auth_apple_login', $payload + $this->deviceFields($body));
    }

    /** POST /v2/auth/google  {id_token, nonce, os?, app_ver?, language?, one_signal_id?} */
    public function google(Request $request): Response
    {
        $body = $request->body;

        return $this->signIn('auth_google_login', [
            'id_token' => $this->requireString($body, 'id_token', 8192),
            'nonce' => $this->requireString($body, 'nonce', 4096),
        ] + $this->deviceFields($body));
    }

    /** POST /v2/auth/password  {email, password, os?, app_ver?, language?, one_signal_id?} */
    public function password(Request $request): Response
    {
        $body = $request->body;

        // Not trimmed: spaces can be part of a password. Never logged.
        $password = $body['password'] ?? null;
        if (!is_string($password) || $password === '') {
            throw new ValidationException(['password' => 'password is required']);
        }
        if (strlen($password) > 1024) {
            throw new ValidationException(['password' => 'password is too long']);
        }

        return $this->signIn('auth_password_login', [
            'email' => $this->requireString($body, 'email', 320),
            'password' => $password,
        ] + $this->deviceFields($body));
    }

    /** POST /v2/auth/logout  (Authorization: Bearer <device token>) */
    public function logout(Request $request): Response
    {
        $token = (string) $request->bearerToken();
        $companyId = $this->tokens->claims($token)['company_id'] ?? null;
        if (!is_int($companyId)) {
            // The static service token, or a token that isn't a device session.
            throw new UnauthorizedException('Not a device session');
        }

        // Revoke first: even if login.ms is unreachable, this device can no longer call the API.
        $this->tokens->revoke($token);
        $this->call('auth_logout', ['token' => $token, 'company_id' => $companyId]);

        return Response::ok(['logged_out' => true]);
    }

    /**
     * Signs in through login.ms and registers the returned device token so the core accepts it.
     *
     * @param array<string, string> $payload
     */
    private function signIn(string $function, array $payload): Response
    {
        $result = $this->call($function, $payload);

        $user = $result['user'] ?? null;
        $token = $result['token'] ?? null;
        if (!is_array($user) || !is_string($token) || $token === '') {
            $this->logger->error('Sign-in response from login.ms has no user/token', ['function' => $function]);
            throw new ServiceException('Sign-in is temporarily unavailable');
        }

        // No TTL: the session lasts until logout (requires a persistent Redis; see README).
        $this->tokens->issue($token, ['company_id' => (int) ($user['company_id'] ?? 0)]);

        if (!$this->tokens->isValid($token)) {
            $this->logger->error('Could not register session token (is Redis configured?)', ['function' => $function]);
            $this->endOrphanedSession($token, (int) ($user['company_id'] ?? 0));
            throw new ApiException('Sessions are temporarily unavailable', 503, 'sessions_unavailable');
        }

        return Response::ok(['user' => $user, 'token' => $token]);
    }

    private function endOrphanedSession(string $token, int $companyId): void
    {
        try {
            $this->call('auth_logout', ['token' => $token, 'company_id' => $companyId]);
        } catch (ApiException $e) {
            $this->logger->error('Could not delete orphaned device row', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function deviceFields(array $body): array
    {
        $fields = [];
        foreach (self::DEVICE_FIELDS as $field => $maxLength) {
            if (isset($body[$field])) {
                $fields[$field] = $this->requireString($body, $field, $maxLength);
            }
        }
        return $fields;
    }

    /**
     * Calls login.ms and returns its `data`, or throws its error with the right status.
     *
     * @param array<string, scalar> $payload
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
