<?php

declare(strict_types=1);

namespace Plugins\Users\Webhooks;

use Pmsrapi\V2\Cache\RateLimiter;
use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ConfigException;
use Pmsrapi\V2\Exception\MethodNotAllowedException;
use Pmsrapi\V2\Exception\RateLimitException;
use Pmsrapi\V2\Exception\UnauthorizedException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\HttpMethod;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Security\TokenStore;
use Pmsrapi\V2\Support\Logger;

final class UsersWebhookHandler
{
    private const int LIMIT = 120; // deliveries per IP per window
    private const int WINDOW_SECONDS = 60;

    public function __construct(
        private readonly Config $config,
        private readonly RedisClient $redis,
        private readonly TokenStore $tokens,
        private readonly RateLimiter $limiter,
        private readonly Logger $logger,
    ) {}

    public function handle(Request $request): Response
    {
        if ($request->method !== HttpMethod::POST) {
            throw new MethodNotAllowedException(['POST']);
        }

        $result = $this->limiter->hit('users-webhook:' . $request->ip, self::LIMIT, self::WINDOW_SECONDS);
        if (!$result->allowed) {
            throw new RateLimitException($result->retryAfter);
        }

        $this->verifySignature($request);

        $event = $request->body['event'] ?? null;
        $payload = $request->body['payload'] ?? null;
        if (!is_string($event) || !is_array($payload)) {
            throw new ValidationException(['event' => 'event and payload are required']);
        }

        return match ($event) {
            'users.removed' => $this->removed($payload),
            default => Response::ok(['event' => $event, 'handled' => false]),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function removed(array $payload): Response
    {
        $tokens = $payload['tokens'] ?? null;
        if (!is_array($tokens)) {
            throw new ValidationException(['tokens' => 'tokens must be a list']);
        }

        $tokens = array_values(array_filter(
            $tokens,
            static fn(mixed $token): bool => is_string($token) && $token !== '' && strlen($token) <= 128,
        ));

        if ($tokens !== [] && !$this->redis->isEnabled()) {
            throw new ApiException('Token store is unavailable', 503, 'sessions_unavailable');
        }

        foreach ($tokens as $token) {
            $this->tokens->revoke($token);
        }

        $this->logger->info('Revoked tokens of removed user', [
            'phonenumber' => $payload['phonenumber'] ?? null,
            'company_id' => $payload['company_id'] ?? null,
            'revoked' => count($tokens),
        ]);

        return Response::ok(['event' => 'users.removed', 'handled' => true, 'revoked' => count($tokens)]);
    }

    private function verifySignature(Request $request): void
    {
        $secret = $this->config->secret('users.webhook_secret');
        if (!is_string($secret) || strlen($secret) < 32) {
            $this->logger->error('users.webhook_secret is missing or shorter than 32 characters');

            throw new ConfigException('Users webhook is not configured');
        }

        $header = (string) $request->header('x-signature', '');
        $expected = 'sha256=' . hash_hmac('sha256', $request->rawBody, $secret);

        if (!hash_equals($expected, $header)) {
            throw new UnauthorizedException('Invalid webhook signature');
        }
    }
}
