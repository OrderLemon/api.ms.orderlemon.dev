<?php

declare(strict_types=1);

namespace Plugins\Notifications\Webhooks;

use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Exception\UnauthorizedException;
use Pmsrapi\V2\Support\Logger;
use RedisException;

// Mailgun webhook signature: HMAC-SHA256(signing key, timestamp . token) ==
// signature. The timestamp must be recent and each token is accepted once
// (Redis, 15 min), so a captured request can't be replayed. Without Redis the
// replay check is skipped and logged; the signature and age checks still run.
final class MailgunSignature
{
    private const int MAX_AGE_SECONDS = 900;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly Logger $logger,
    ) {}

    public function verify(array $signature, string $signingKey): void
    {
        $timestamp = (string) ($signature['timestamp'] ?? '');
        $token = (string) ($signature['token'] ?? '');
        $given = (string) ($signature['signature'] ?? '');

        if ($timestamp === '' || $token === '' || $given === '' || !ctype_digit($timestamp)) {
            throw new UnauthorizedException('Missing Mailgun signature');
        }
        if (!hash_equals(hash_hmac('sha256', $timestamp . $token, $signingKey), $given)) {
            throw new UnauthorizedException('Invalid Mailgun signature');
        }
        if (abs(time() - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            throw new UnauthorizedException('Stale Mailgun signature');
        }

        $this->assertFirstUse($token);
    }

    private function assertFirstUse(string $token): void
    {
        if (!$this->redis->isEnabled()) {
            return;
        }

        try {
            $fresh = $this->redis->connection()->set(
                'notifications:mailgun-token:' . hash('sha256', $token),
                '1',
                ['nx', 'ex' => self::MAX_AGE_SECONDS],
            );
        } catch (RedisException $e) {
            $this->logger->warning('Mailgun replay check skipped: Redis unavailable', ['error' => $e->getMessage()]);

            return;
        }

        if ($fresh !== true) {
            throw new UnauthorizedException('Replayed Mailgun signature');
        }
    }
}
