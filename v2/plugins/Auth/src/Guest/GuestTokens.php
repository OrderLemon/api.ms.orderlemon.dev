<?php

declare(strict_types=1);

namespace Plugins\Auth\Guest;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ConfigException;
use Pmsrapi\V2\Exception\UnauthorizedException;

/**
 * Short-lived guest tokens for the public sign-in entry point (/auth.php).
 *
 * Stateless: a token is base64url(expires_at . "." . random) plus an HMAC-SHA256 signature
 * made with "auth.guest_token_secret". Nothing is stored per token, so issuing many costs
 * nothing (no Redis flooding). Guest tokens are never put in the TokenStore, so the core
 * rejects them on every /v2 route: they only work on /auth.php.
 */
final class GuestTokens
{
    public const int TTL_SECONDS = 600;
    private const int MIN_SECRET_LENGTH = 32;

    public function __construct(
        private readonly Config $config,
    ) {}

    /**
     * @return array{token: string, expires_at: int}
     */
    public function issue(): array
    {
        $expiresAt = time() + self::TTL_SECONDS;
        $payload = self::base64Url($expiresAt . '.' . bin2hex(random_bytes(16)));

        return ['token' => $payload . '.' . $this->sign($payload), 'expires_at' => $expiresAt];
    }

    public function verify(?string $token): void
    {
        [$payload, $signature] = explode('.', (string) $token, 2) + ['', ''];
        if ($payload === '' || !hash_equals($this->sign($payload), $signature)) {
            throw new UnauthorizedException('Invalid guest token');
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        $expiresAt = (int) strstr((string) $decoded, '.', true);
        if ($expiresAt < time()) {
            throw new ApiException('Guest token expired', 401, 'guest_token_expired');
        }
    }

    private function sign(string $payload): string
    {
        return self::base64Url(hash_hmac('sha256', 'guest:' . $payload, $this->secret(), true));
    }

    private function secret(): string
    {
        $secret = $this->config->secret('auth.guest_token_secret');
        if (!is_string($secret) || strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new ConfigException('auth.guest_token_secret must be set (at least 32 characters)');
        }
        return $secret;
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
