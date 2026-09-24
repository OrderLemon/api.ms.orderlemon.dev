<?php

declare(strict_types=1);

namespace Plugins\Campaigns\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

/**
 * Thin proxy — no local payload validation. campaigns.ms is the source of
 * truth for campaign field rules (driven by its own config catalog, see
 * `GET /campaigns/config`), and this gateway used to keep its own separate,
 * hardcoded copy of those rules. That copy drifted out of sync (it never
 * learned about campaign_start/campaign_end/campaign_config_id becoming
 * required), so it's removed here rather than kept in sync by hand.
 *
 * call() below goes through ServiceClient::stream() rather than
 * ServiceClient::call() — deliberately. call() discards the response BODY
 * on any 4xx/5xx (it only keeps the HTTP status), so a real validation
 * error from campaigns.ms — field-level messages, the actual reason — never
 * reaches the client; the gateway could only report a generic "HTTP 422".
 */
final class CampaignsController
{

    private const array STATUS_BY_ERROR_CODE = [
        'not_found' => 404,
        'conflict' => 409,
        'validation_failed' => 422,
        'method_not_allowed' => 405,
        'rate_limited' => 429,
        'database_error' => 500,
    ];

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

    public function getConfigOptions(): Response
    {
        return $this->call('campaigns_config_options', []);
    }

    public function getCampaigns(string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);

        return $this->call('campaigns_get', ['shop_id' => $shopId]);
    }

    public function getCampaign(string $shopId, string $id): Response
    {
        $shopId = $this->requireShopId($shopId);
        $id = $this->requirePositiveInt($id, 'id');

        return $this->call('campaigns_get_campaign', ['shop_id' => $shopId, 'id' => $id]);
    }

    public function createCampaign(Request $request, string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);

        return $this->call('campaigns_create', ['shop_id' => $shopId], $request->body);
    }

    public function updateCampaign(Request $request, string $shopId, string $id): Response
    {
        $shopId = $this->requireShopId($shopId);
        $id = $this->requirePositiveInt($id, 'id');

        return $this->call('campaigns_update', ['shop_id' => $shopId, 'id' => $id], $request->body);
    }

    public function deleteCampaign(string $shopId, string $id): Response
    {
        $shopId = $this->requireShopId($shopId);
        $id = $this->requirePositiveInt($id, 'id');

        return $this->call('campaigns_delete', ['shop_id' => $shopId, 'id' => $id]);
    }

    private function call(string $function, array $params, array $payload = []): Response
    {
        $envelope = null;
        foreach ($this->serviceClient->stream($function, $params, $payload) as $record) {
            $envelope = $record;
            break; // exactly one record expected: the whole response body.
        }

        // No record at all: this framework's Response only ever sends a
        // truly empty body for 204 No Content (every other status — 200,
        // 201, 4xx, 5xx — carries a JSON envelope)
        if ($envelope === null) {
            return Response::ok([]);
        }

        if (!is_array($envelope)) {
            $this->logger->error('Non-object response from proxied call', ['function' => $function]);

            throw new ServiceException("Non-object response from '{$function}'");
        }

        if (!($envelope['success'] ?? false)) {
            $error = is_array($envelope['error'] ?? null) ? $envelope['error'] : [];
            $this->logger->error('Proxied call failed', ['function' => $function, 'error' => $error]);

            $this->throwFor($error);
        }

        return Response::ok(is_array($envelope['data'] ?? null) ? $envelope['data'] : []);
    }

    private function throwFor(array $error): never
    {
        $code = is_string($error['code'] ?? null) ? $error['code'] : 'service_error';
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'Request failed';
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        throw new ApiException($message, self::STATUS_BY_ERROR_CODE[$code] ?? 502, $code, $details);
    }

    private function requireShopId(string $value): int
    {
        $shopId = filter_var($value, FILTER_VALIDATE_INT);

        if ($shopId === false || $shopId <= 0) {
            $this->fail(new ValidationException(['shop_id' => 'A valid shop_id is required']), ['shop_id' => $value]);
        }

        return $shopId;
    }

    private function requirePositiveInt(string $value, string $field): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);

        if ($int === false || $int <= 0) {
            $this->fail(new ValidationException([$field => "A valid {$field} is required"]), [$field => $value]);
        }

        return $int;
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
