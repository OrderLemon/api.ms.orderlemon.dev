<?php

declare(strict_types=1);

namespace Plugins\Imports\Website;

use JsonException;
use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Support\Logger;
use RedisException;

// What the scraper's webhook does not carry but the scraping.finished notification needs:
// the company of the shop, who started the job and the website. Remembered in Redis when
// MyShop starts a job (the company comes from the session, never from the scraper), read
// back when the webhook arrives. Also makes sure one job is notified only once (the scraper
// retries its webhook up to 3 times when an answer is lost).
//
// Redis degrades, never crashes: without it the job still starts, and the webhook is
// answered but no notification is sent (logged).
final class ScrapeJobContext
{
    private const string PREFIX = 'imports:website:';
    // A job waits in the scraper's queue, then runs at most 60 minutes.
    private const int TTL_SECONDS = 86_400;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly Logger $logger,
    ) {}

    public function remember(string $jobId, int $shopId, ?int $companyId, string $startedBy, string $url): void
    {
        if (!$this->redis->isEnabled()) {
            return;
        }

        try {
            $value = json_encode([
                'shop_id' => $shopId,
                'company_id' => $companyId,
                'started_by' => $startedBy,
                'url' => $url,
            ], JSON_THROW_ON_ERROR);
            $this->redis->connection()->set(self::PREFIX . 'job:' . $jobId, $value, ['ex' => self::TTL_SECONDS]);
        } catch (RedisException | JsonException $e) {
            $this->logger->warning('Scrape job context not stored: no notification for this job', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{shop_id: int, company_id: int|null, started_by: string, url: string}|null
     */
    public function find(string $jobId): ?array
    {
        if (!$this->redis->isEnabled()) {
            return null;
        }

        try {
            $value = $this->redis->connection()->get(self::PREFIX . 'job:' . $jobId);
        } catch (RedisException $e) {
            $this->logger->warning('Scrape job context not readable', ['job_id' => $jobId, 'error' => $e->getMessage()]);

            return null;
        }

        $context = is_string($value) ? json_decode($value, true) : null;
        if (!is_array($context)) {
            return null;
        }

        return [
            'shop_id' => (int) ($context['shop_id'] ?? 0),
            'company_id' => is_int($context['company_id'] ?? null) ? $context['company_id'] : null,
            'started_by' => (string) ($context['started_by'] ?? ''),
            'url' => (string) ($context['url'] ?? ''),
        ];
    }

    // True the first time a job's webhook is seen; false for a repeat. Without Redis: true.
    public function firstFinish(string $jobId): bool
    {
        if (!$this->redis->isEnabled()) {
            return true;
        }

        try {
            return $this->redis->connection()->set(
                self::PREFIX . 'finished:' . $jobId,
                '1',
                ['nx', 'ex' => self::TTL_SECONDS],
            ) === true;
        } catch (RedisException $e) {
            $this->logger->warning('Scrape webhook repeat check skipped: Redis unavailable', ['error' => $e->getMessage()]);

            return true;
        }
    }
}
