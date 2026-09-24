<?php

declare(strict_types=1);

namespace Plugins\Campaigns\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ConflictException;
use Pmsrapi\V2\Exception\NotFoundException;
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
 * required), so it's removed here rather than kept in sync by hand —
 * campaigns.ms's 422 is translated by call() below like any other error.
 */
final class CampaignsController
{
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
        try {
            $response = $this->serviceClient->call($function, $params, $payload);
        } catch (ServiceException $ex) {
            $this->logger->error('Proxied call failed', [
                'function' => $function,
                'status' => $ex->statusCode(),
                'message' => $ex->getMessage(),
            ]);

            throw match ($ex->statusCode()) {
                404 => new NotFoundException('That campaign could not be found.'),
                409 => new ConflictException('A campaign with that configuration already exists.'),
                422 => new ValidationException([], 'The information you provided could not be processed.'),
                429 => new ServiceException(
                    'Too many requests — please wait a moment and try again.',
                    429,
                    'rate_limited',
                ),
                default => new ServiceException(
                    'Something went wrong processing this request. Please try again.',
                    $ex->statusCode() >= 500 ? 502 : $ex->statusCode(),
                ),
            };
        }

        return Response::ok($response);
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
