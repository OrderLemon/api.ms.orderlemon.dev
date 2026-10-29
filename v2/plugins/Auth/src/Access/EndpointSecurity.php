<?php

declare(strict_types=1);

namespace Plugins\Auth\Access;

use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\UnauthorizedException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Security\TokenStore;
use Pmsrapi\V2\Support\Logger;
use RedisException;
use WeakMap;

// Keeps device sessions inside their own shops and their role's features.
//
// The static service token passes untouched. A device token is checked by login.ms
// (authorize_shop_access: session, user, shop and company enabled, a role in the shop) and
// the allow is cached in Redis (auth.shop_access.cache_ttl seconds, default 60) as a compact
// {role, f: {feature: 0|1}}.
// A deny is never cached. The required feature is checked against the cached map on every
// call. If login.ms can't answer, the call is refused (503), never let through.
final class EndpointSecurity
{
    private const int DEFAULT_CACHE_TTL = 60;
    private const string DEFAULT_CACHE_PREFIX = 'shopaccess:';

    private readonly int $cacheTtl;
    private readonly string $cachePrefix;

    // The granted access per request, so controllers and repeat checks don't hit Redis again.
    private WeakMap $granted;

    public function __construct(
        private readonly Config $config,
        private readonly TokenStore $tokens,
        private readonly ServiceClient $client,
        private readonly RedisClient $redis,
        private readonly Logger $logger,
    ) {
        $this->granted = new WeakMap();

        $ttl = filter_var($config->secret('auth.shop_access.cache_ttl', self::DEFAULT_CACHE_TTL), FILTER_VALIDATE_INT);
        $this->cacheTtl = $ttl !== false && $ttl > 0 ? $ttl : self::DEFAULT_CACHE_TTL;

        $prefix = $config->secret('auth.shop_access.cache_prefix', self::DEFAULT_CACHE_PREFIX);
        $this->cachePrefix = is_string($prefix) && $prefix !== '' ? $prefix : self::DEFAULT_CACHE_PREFIX;
    }

    // Call first thing in every shop handler. $feature null = shop access only.
    public function approveCall(Request $request, int|string $shopId, ?string $feature = null): void
    {
        $token = (string) $request->bearerToken();
        if ($this->isServiceToken($token)) {
            return;
        }

        $companyId = $this->companyId($token);
        $shopId = filter_var($shopId, FILTER_VALIDATE_INT);
        if ($shopId === false || $shopId < 1) {
            throw new ValidationException(['shop_id' => 'A valid shop_id is required']);
        }

        $access = $this->granted[$request] ?? null;
        if ($access === null || $access['shop_id'] !== $shopId) {
            $key = $this->cachePrefix . hash('sha256', $token) . ':' . $shopId;
            $access = $this->cached($key);
            if ($access === null) {
                $access = self::compact($this->authorize($token, $companyId, $shopId));
                $this->remember($key, $access);
            }
            $this->granted[$request] = $access;
        }

        if ($feature !== null) {
            $this->assertFeature($access, $feature);
        }
    }

    // {phonenumber, role_id, role, company_id, shop_id, f}, or null for the service token / before approveCall().
    public function access(Request $request): ?array
    {
        return $this->granted[$request] ?? null;
    }

    private function assertFeature(array $access, string $feature): void
    {
        if (!array_key_exists($feature, $access['f'])) {
            // A route names a feature the registry doesn't have: deny, never let through.
            $this->logger->error('Route requires an unknown permission feature', ['feature' => $feature]);
            throw self::featureForbidden();
        }
        if ($access['f'][$feature] !== 1) {
            throw self::featureForbidden();
        }
    }

    private function isServiceToken(string $token): bool
    {
        return hash_equals($this->config->serverToken(), $token);
    }

    // The company comes from the token's claims, never from the client.
    private function companyId(string $token): int
    {
        $companyId = $this->tokens->claims($token)['company_id'] ?? null;
        if (!is_int($companyId) || $companyId < 1) {
            throw new UnauthorizedException('Not a device session');
        }

        return $companyId;
    }

    private function authorize(string $token, int $companyId, int $shopId): array
    {
        try {
            return $this->client->call('authorize_shop_access', [], [
                'token' => $token,
                'company_id' => $companyId,
                'shop_id' => $shopId,
            ]);
        } catch (ServiceException $e) {
            throw match ($e->statusCode()) {
                401 => new UnauthorizedException('Session is no longer valid'),
                403 => self::forbidden(),
                default => $this->unavailable($e),
            };
        }
    }

    // Keeps only what the guard needs; labels and categories stay in login.ms.
    private static function compact(array $granted): array
    {
        $features = [];
        foreach ($granted['permissions'] ?? [] as $permission) {
            $features[(string) $permission['feature']] = ($permission['enabled'] ?? false) === true ? 1 : 0;
        }

        return [
            'phonenumber' => (string) ($granted['phonenumber'] ?? ''),
            'role_id' => (int) ($granted['role_id'] ?? 0),
            'role' => (string) ($granted['role'] ?? ''),
            'company_id' => (int) ($granted['company_id'] ?? 0),
            'shop_id' => (int) ($granted['shop_id'] ?? 0),
            'f' => $features,
        ];
    }

    private function cached(string $key): ?array
    {
        if (!$this->redis->isEnabled()) {
            return null;
        }

        try {
            $raw = $this->redis->connection()->get($key);
            if (is_string($raw) && json_validate($raw)) {
                $access = json_decode($raw, true);
                return is_array($access) && is_array($access['f'] ?? null) ? $access : null;
            }
        } catch (RedisException $e) {
            $this->logger->warning('Shop access cache read failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function remember(string $key, array $access): void
    {
        if (!$this->redis->isEnabled()) {
            return;
        }

        try {
            $this->redis->connection()->setex($key, $this->cacheTtl, (string) json_encode($access));
        } catch (RedisException $e) {
            $this->logger->warning('Shop access cache write failed', ['error' => $e->getMessage()]);
        }
    }

    private function unavailable(ServiceException $e): ApiException
    {
        $this->logger->error('Shop access check failed', ['error' => $e->getMessage()]);

        return new ApiException('Shop access check is temporarily unavailable', 503, 'shop_access_unavailable');
    }

    private static function forbidden(): ApiException
    {
        return new ApiException('Not allowed to access this shop', 403, 'shop_forbidden');
    }

    private static function featureForbidden(): ApiException
    {
        return new ApiException('Your role has no access to this feature', 403, 'feature_forbidden');
    }
}
