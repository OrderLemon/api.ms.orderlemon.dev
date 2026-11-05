<?php

declare(strict_types=1);

namespace Plugins\Imports\Website;

use JsonException;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ConfigException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Http\HttpMethod;
use Pmsrapi\V2\Support\Logger;

// HTTP client for the website scraper (ol_api_scrapy, not a pmsrapi service, so not
// ServiceClient: it has no function_map and signs with HMAC instead of a Bearer token).
//
// Every request carries X-Timestamp and X-Signature: sha256=HMAC-SHA256 of
// "timestamp.METHOD.path.body" (path with its query string, body empty for GET/DELETE),
// keyed with scraper.hmac_secret. The scraper refuses a timestamp more than 5 minutes off.
//
// Secret config (api.ms.json):
//   "scraper": {"url": "http://127.0.0.1:8090", "hmac_secret": "<same as the scraper's HMAC_SECRET>"}
//
// The scraper answers errors as {"error": {"code", "message"}}. A 4xx is passed on with its
// code (MyShop needs `job_not_finished`, `no_products_found`, `invalid_url`…); a 5xx, a
// 401 (our secret is wrong) or no answer at all becomes a generic 502.
final class ScraperClient
{
    private const int TIMEOUT_SECONDS = 30;
    private const int CONNECT_TIMEOUT_SECONDS = 5;
    private const string UNAVAILABLE = 'The website import is not available right now. Please try again later.';

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, mixed>|null $body  JSON body (POST only)
     * @param array<string, scalar>     $query
     * @return array<string, mixed> the decoded JSON answer
     */
    public function request(HttpMethod $method, string $path, ?array $body = null, array $query = []): array
    {
        [$baseUrl, $secret] = $this->settings();

        $basePath = rtrim((string) (parse_url($baseUrl, PHP_URL_PATH) ?? ''), '/');
        $pathWithQuery = $basePath . $path . ($query === [] ? '' : '?' . http_build_query($query));

        try {
            $rawBody = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new ServiceException(self::UNAVAILABLE, 502, 'service_error', $e);
        }

        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac(
            'sha256',
            "{$timestamp}.{$method->value}.{$pathWithQuery}.{$rawBody}",
            $secret,
        );

        $url = rtrim($baseUrl, '/') . $path . ($query === [] ? '' : '?' . http_build_query($query));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method->value,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Timestamp: ' . $timestamp,
                'X-Signature: ' . $signature,
            ],
        ]);
        if ($rawBody !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        }

        $answer = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($answer) || $status === 0) {
            $this->logger->error('Scraper unreachable', ['method' => $method->value, 'path' => $path, 'error' => $error]);

            throw new ServiceException(self::UNAVAILABLE, 502);
        }

        $decoded = $answer === '' ? [] : json_decode($answer, true);
        if (!is_array($decoded)) {
            $this->logger->error('Scraper answered no JSON', ['method' => $method->value, 'path' => $path, 'status' => $status]);

            throw new ServiceException(self::UNAVAILABLE, 502);
        }

        if ($status >= 200 && $status < 300) {
            return $decoded;
        }

        $code = (string) ($decoded['error']['code'] ?? 'service_error');
        $message = (string) ($decoded['error']['message'] ?? 'The website import failed.');

        if ($status === 401 || $status === 403 || $status >= 500) {
            // 401/403: our secret or clock is wrong. Never shown to the merchant.
            $this->logger->error('Scraper refused or failed', [
                'method' => $method->value,
                'path' => $path,
                'status' => $status,
                'code' => $code,
                'message' => $message,
            ]);

            throw new ServiceException(self::UNAVAILABLE, 502);
        }

        $this->logger->info('Scraper answered an error', ['method' => $method->value, 'path' => $path, 'status' => $status, 'code' => $code]);

        throw new ApiException($message, $status, $code);
    }

    // The shared secret, also used by ScraperWebhookHandler to check the scraper's webhook.
    public function secret(): string
    {
        return $this->settings()[1];
    }

    /**
     * @return array{0: string, 1: string} base URL and HMAC secret
     */
    private function settings(): array
    {
        $url = $this->config->secret('scraper.url');
        $secret = $this->config->secret('scraper.hmac_secret');

        if (!is_string($url) || $url === '' || !is_string($secret) || $secret === '') {
            $this->logger->error('scraper.url or scraper.hmac_secret is missing in the secret config');

            throw new ConfigException('The website import is not configured');
        }

        return [$url, $secret];
    }
}
