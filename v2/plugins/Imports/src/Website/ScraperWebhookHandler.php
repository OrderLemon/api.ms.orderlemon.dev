<?php

declare(strict_types=1);

namespace Plugins\Imports\Website;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Pmsrapi\V2\Cache\RateLimiter;
use Pmsrapi\V2\Exception\MethodNotAllowedException;
use Pmsrapi\V2\Exception\RateLimitException;
use Pmsrapi\V2\Exception\UnauthorizedException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\HttpMethod;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Queue\WebhookDispatcher;
use Pmsrapi\V2\Support\Logger;

// POST /v2/webhooks/scraper (v2/.htaccess → plugins/Imports/hooks/scraper.php) — the
// scraper's "job finished" webhook. No Bearer token: the scraper signs it like our calls to
// it (X-Timestamp + X-Signature: sha256=HMAC("ts.POST.path.body", scraper.hmac_secret)),
// and it is refused when more than 5 minutes off.
//
// Body: {event: "job.finished", job_id, shop_id, status, error_code, products_found,
// categories_found, images_found, created_at, started_at, finished_at, requested_by}.
//
// Emits notifications.ms's `scraping.finished` (WebhookDispatcher → subscribers of that
// event, i.e. notifications.ms ingest), which sends the "Website scraping finished/failed"
// email and push. The company comes from ScrapeJobContext (stored when MyShop started the
// job); a job started some other way is answered 200 but not notified.
final class ScraperWebhookHandler
{
    private const int MAX_AGE_SECONDS = 300;
    private const int LIMIT = 120; // deliveries per IP per window
    private const int WINDOW_SECONDS = 60;
    private const string EVENT = 'scraping.finished';
    private const string SOURCE = 'scraper';
    private const string JOB_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly ScraperClient $scraper,
        private readonly ScrapeJobContext $context,
        private readonly WebhookDispatcher $webhooks,
        private readonly RateLimiter $limiter,
        private readonly Logger $logger,
    ) {}

    // $requestUri: the path + query as the scraper sent it ($_SERVER['REQUEST_URI'], before
    // the rewrite), which is what it signed.
    public function handle(Request $request, string $requestUri): Response
    {
        if ($request->method !== HttpMethod::POST) {
            throw new MethodNotAllowedException(['POST']);
        }

        $limit = $this->limiter->hit('scraper-webhook:' . $request->ip, self::LIMIT, self::WINDOW_SECONDS);
        if (!$limit->allowed) {
            throw new RateLimitException($limit->retryAfter);
        }

        $this->verify($request, $requestUri);

        $body = $request->body;
        $jobId = is_string($body['job_id'] ?? null) ? strtolower($body['job_id']) : '';
        $shopId = filter_var($body['shop_id'] ?? null, FILTER_VALIDATE_INT);
        if (($body['event'] ?? null) !== 'job.finished' || preg_match(self::JOB_ID_PATTERN, $jobId) !== 1 || $shopId === false) {
            throw new ValidationException(['body' => 'event job.finished with job_id and shop_id is required']);
        }

        $this->logger->info('Scrape job finished', [
            'job_id' => $jobId,
            'shop_id' => $shopId,
            'status' => $body['status'] ?? null,
            'error_code' => $body['error_code'] ?? null,
            'products_found' => $body['products_found'] ?? null,
        ]);

        if (!$this->context->firstFinish($jobId)) {
            return Response::ok(['notified' => false, 'reason' => 'already_received']);
        }

        $context = $this->context->find($jobId);
        if ($context === null || $context['company_id'] === null || $context['shop_id'] !== $shopId) {
            $this->logger->warning('Scrape job finished without a known company: no notification', ['job_id' => $jobId, 'shop_id' => $shopId]);

            return Response::ok(['notified' => false, 'reason' => 'unknown_job']);
        }

        $this->webhooks->emit(self::EVENT, [
            'event_id' => Ulid::generate(),
            'source' => self::SOURCE,
            'company_id' => $context['company_id'],
            'shop_id' => $shopId,
            'occurred_at' => self::isoDate($body['finished_at'] ?? null) ?? date(DateTimeInterface::ATOM),
            'data' => [
                'job_id' => $jobId,
                'status' => (string) ($body['status'] ?? ''),
                'started_by' => self::startedBy($body['requested_by'] ?? null, $context['started_by']),
                'source_url' => $context['url'],
                'started_at' => self::isoDate($body['started_at'] ?? null),
                'finished_at' => self::isoDate($body['finished_at'] ?? null),
                'totals' => [
                    'products' => (int) ($body['products_found'] ?? 0),
                    'categories' => (int) ($body['categories_found'] ?? 0),
                    'photos' => (int) ($body['images_found'] ?? 0),
                ],
                // Only a failed job shows an error: `done` + `limit_reached`/`timeout` is a
                // finished scrape with what it found, not an error for the merchant.
                'error' => ($body['status'] ?? null) === 'failed' && is_string($body['error_code'] ?? null)
                    ? $body['error_code']
                    : null,
            ],
        ]);

        return Response::ok(['notified' => true]);
    }

    private function verify(Request $request, string $requestUri): void
    {
        $timestamp = (string) $request->header('x-timestamp', '');
        $given = (string) $request->header('x-signature', '');

        if (!ctype_digit($timestamp) || $given === '') {
            throw new UnauthorizedException('Missing scraper signature');
        }

        $expected = 'sha256=' . hash_hmac(
            'sha256',
            "{$timestamp}.POST.{$requestUri}.{$request->rawBody}",
            $this->scraper->secret(),
        );
        if (!hash_equals($expected, $given)) {
            $this->logger->warning('Scraper webhook with a wrong signature', ['ip' => $request->ip, 'path' => $requestUri]);

            throw new UnauthorizedException('Invalid scraper signature');
        }
        if (abs(time() - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            throw new UnauthorizedException('Stale scraper signature');
        }
    }

    // notifications.ms needs a non-empty started_by: what MyShop sent, else who started it.
    private static function startedBy(mixed $requestedBy, string $remembered): string
    {
        if (is_string($requestedBy) && trim($requestedBy) !== '') {
            return trim($requestedBy);
        }

        return $remembered !== '' ? $remembered : 'MyShop';
    }

    // The scraper sends UTC as "2026-11-05T13:11:17Z"; notifications.ms wants an offset.
    private static function isoDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
        } catch (Exception) {
            return null;
        }
    }
}
