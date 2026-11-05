<?php

declare(strict_types=1);

namespace Plugins\Imports\Controllers;

use Plugins\Auth\Access\EndpointSecurity;
use Plugins\Imports\Website\ScrapeJobContext;
use Plugins\Imports\Website\ScraperClient;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\NotFoundException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\HttpMethod;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

// Import from a website: MyShop's side of the scraper (ol_api_scrapy).
//
//   POST   /v2/imports/website/{shop_id}/detect                    {"url"}  → platform, confidence
//   POST   /v2/imports/website/{shop_id}/jobs                      {"url", "max_products"?, "max_minutes"?, "started_by"?, "company_id"?}
//   GET    /v2/imports/website/{shop_id}/jobs/{job_id}             status and counters
//   DELETE /v2/imports/website/{shop_id}/jobs/{job_id}             cancel (what was found stays)
//   GET    /v2/imports/website/{shop_id}/jobs/{job_id}/results     ?category_limit=10 for a WhatsApp-only shop
//   GET    /v2/imports/website/{shop_id}/jobs/{job_id}/diff        against the shop's previous crawl
//
// Same permission as the file imports (products.import). A job belongs to the shop it was
// started for: any other shop_id gets 404, so one shop can never read another's job.
// The scraper's own answers are passed on as they are (its error codes included).
final class WebsiteController
{
    private const string FEATURE = 'products.import';
    private const string JOB_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const int URL_MAX_LENGTH = 2048;
    private const int STARTED_BY_MAX_LENGTH = 255;

    public function __construct(
        private readonly ScraperClient $scraper,
        private readonly ScrapeJobContext $context,
        private readonly EndpointSecurity $security,
        private readonly Logger $logger,
    ) {}

    public function detect(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $shopId, self::FEATURE);
        $this->requireShopId($shopId);

        return Response::ok($this->scraper->request(HttpMethod::POST, '/v1/detect', [
            'url' => $this->requireUrl($request->body['url'] ?? null),
        ]));
    }

    public function createJob(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $shopId, self::FEATURE);
        $shop = $this->requireShopId($shopId);
        $body = $request->body;

        $url = $this->requireUrl($body['url'] ?? null);
        $access = $this->security->access($request);

        // A device session: company and user from the session. The service token: from the body.
        $companyId = $access !== null ? $access['company_id'] : $this->optionalPositiveInt($body['company_id'] ?? null, 'company_id');
        $startedBy = $this->startedBy($body['started_by'] ?? null, $access);

        $job = [
            'shop_id' => $shop,
            'url' => $url,
            'requested_by' => $startedBy === '' ? null : $startedBy,
        ];
        foreach (['max_products', 'max_minutes'] as $limit) {
            $value = $this->optionalPositiveInt($body[$limit] ?? null, $limit);
            if ($value !== null) {
                $job[$limit] = $value;
            }
        }

        $created = $this->scraper->request(HttpMethod::POST, '/v1/jobs', $job);

        $jobId = (string) ($created['job_id'] ?? '');
        if ($jobId !== '') {
            $this->context->remember($jobId, $shop, $companyId, $startedBy, (string) ($created['url'] ?? $url));
        }

        $this->logger->info('Website import started', ['shop_id' => $shop, 'job_id' => $jobId, 'url' => $url]);

        return Response::created($created);
    }

    public function getJob(Request $request, string $shopId, string $jobId): Response
    {
        $this->security->approveCall($request, $shopId, self::FEATURE);

        return Response::ok($this->ownedJob($shopId, $jobId));
    }

    public function cancelJob(Request $request, string $shopId, string $jobId): Response
    {
        $this->security->approveCall($request, $shopId, self::FEATURE);
        $this->ownedJob($shopId, $jobId);

        return Response::ok($this->scraper->request(HttpMethod::DELETE, "/v1/jobs/{$jobId}"));
    }

    public function results(Request $request, string $shopId, string $jobId): Response
    {
        $this->security->approveCall($request, $shopId, self::FEATURE);
        $this->ownedJob($shopId, $jobId);

        $query = [];
        $limit = $this->optionalPositiveInt($request->query['category_limit'] ?? null, 'category_limit');
        if ($limit !== null) {
            $query['category_limit'] = $limit;
        }

        return Response::ok($this->scraper->request(HttpMethod::GET, "/v1/jobs/{$jobId}/results", null, $query));
    }

    public function diff(Request $request, string $shopId, string $jobId): Response
    {
        $this->security->approveCall($request, $shopId, self::FEATURE);
        $this->ownedJob($shopId, $jobId);

        return Response::ok($this->scraper->request(HttpMethod::GET, "/v1/jobs/{$jobId}/diff"));
    }

    /**
     * The job, only when it belongs to this shop; 404 otherwise (also for another shop's job).
     *
     * @return array<string, mixed>
     */
    private function ownedJob(string $shopId, string $jobId): array
    {
        $shop = $this->requireShopId($shopId);
        $jobId = strtolower($jobId);
        if (preg_match(self::JOB_ID_PATTERN, $jobId) !== 1) {
            $this->fail(new NotFoundException('That website import is not available.'), ['job_id' => $jobId]);
        }

        try {
            $job = $this->scraper->request(HttpMethod::GET, "/v1/jobs/{$jobId}");
        } catch (ApiException $e) {
            if ($e->statusCode() === 404) {
                throw new NotFoundException('That website import is not available.');
            }
            throw $e;
        }

        if ((int) ($job['shop_id'] ?? 0) !== $shop) {
            $this->fail(new NotFoundException('That website import is not available.'), ['job_id' => $jobId, 'shop_id' => $shop]);
        }

        return $job;
    }

    private function requireShopId(string $value): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || $int <= 0) {
            $this->fail(new ValidationException(['shop_id' => 'A valid shop_id is required']), ['shop_id' => $value]);
        }

        return $int;
    }

    private function requireUrl(mixed $value): string
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url !== '' && preg_match('#^https?://#i', $url) !== 1) {
            $url = 'https://' . $url;
        }

        if ($url === '' || strlen($url) > self::URL_MAX_LENGTH || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->fail(new ValidationException(['url' => 'Please enter the address of your website.']));
        }

        return $url;
    }

    private function optionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || $int <= 0) {
            $this->fail(new ValidationException([$field => "{$field} must be a positive number"]), [$field => $value]);
        }

        return $int;
    }

    // Shown in the notification ("started by"): what MyShop sends, else the session's phone number.
    private function startedBy(mixed $value, ?array $access): string
    {
        if ($value !== null && (!is_string($value) || mb_strlen($value) > self::STARTED_BY_MAX_LENGTH)) {
            $this->fail(new ValidationException(['started_by' => 'started_by is not valid']));
        }

        $startedBy = is_string($value) ? trim($value) : '';

        return $startedBy !== '' ? $startedBy : (string) ($access['phonenumber'] ?? '');
    }

    private function fail(ApiException $e, array $context = []): never
    {
        $this->logger->warning('Rejected request', [
            'error_code' => $e->errorCode(),
            'status' => $e->statusCode(),
            ...$context,
        ]);

        throw $e;
    }
}
