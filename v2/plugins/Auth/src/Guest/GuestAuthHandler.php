<?php

declare(strict_types=1);

namespace Plugins\Auth\Guest;

use Plugins\Auth\Controllers\SignInController;
use Pmsrapi\V2\Cache\RateLimiter;
use Pmsrapi\V2\Exception\MethodNotAllowedException;
use Pmsrapi\V2\Exception\NotFoundException;
use Pmsrapi\V2\Exception\RateLimitException;
use Pmsrapi\V2\Http\HttpMethod;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

/**
 * The public sign-in entry point behind /auth.php (outside the v2 front controller, so the
 * core Bearer check doesn't apply). Access is controlled here instead:
 *
 *   POST /auth.php?a=session                 → {token, expires_at, config}   (no token needed)
 *   POST /auth.php?a=apple|google|password   → {user, token}                 (Bearer <guest token>)
 *
 * Both are rate-limited per client IP. The sign-in actions reuse SignInController, so they
 * behave exactly like the former /v2/auth/* routes (device token registered in TokenStore).
 */
final class GuestAuthHandler
{
    private const int SESSION_LIMIT = 30;  // guest tokens per IP per window
    private const int SIGN_IN_LIMIT = 10;  // sign-in attempts per IP per window
    private const int WINDOW_SECONDS = 60;

    public function __construct(
        private readonly GuestTokens $guests,
        private readonly SignInController $signIn,
        private readonly RateLimiter $limiter,
    ) {}

    public function handle(Request $request, string $action): Response
    {
        if ($request->method !== HttpMethod::POST) {
            throw new MethodNotAllowedException(['POST']);
        }

        return match ($action) {
            'session' => $this->session($request),
            'apple' => $this->signIn($request, fn(): Response => $this->signIn->apple($request)),
            'google' => $this->signIn($request, fn(): Response => $this->signIn->google($request)),
            'password' => $this->signIn($request, fn(): Response => $this->signIn->password($request)),
            default => throw new NotFoundException('Unknown action'),
        };
    }

    private function session(Request $request): Response
    {
        $this->throttle('auth-session:' . $request->ip, self::SESSION_LIMIT);

        return Response::ok($this->guests->issue() + ['config' => $this->signIn->providerConfig()]);
    }

    private function signIn(Request $request, \Closure $signIn): Response
    {
        $this->throttle('auth-signin:' . $request->ip, self::SIGN_IN_LIMIT);
        $this->guests->verify($request->bearerToken());

        return $signIn();
    }

    private function throttle(string $identifier, int $max): void
    {
        $result = $this->limiter->hit($identifier, $max, self::WINDOW_SECONDS);
        if (!$result->allowed) {
            throw new RateLimitException($result->retryAfter);
        }
    }
}
