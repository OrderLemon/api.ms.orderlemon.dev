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
use Pmsrapi\V2\Services\ValidationService;
use Pmsrapi\V2\Support\Logger;

final class CampaignsController
{
    private const int CAMPAIGN_NAME_MAX_LENGTH = 64;
    private const int PROMO_CODE_MAX_LENGTH = 4;
    private const int TEMPLATE_ID_MAX_LENGTH = 64;
    private const int HEADER_MAX_LENGTH = 20;
    private const int BODY_MAX_LENGTH = 280;
    private const int PICTURE_URL_MAX_LENGTH = 300;
    private const int FOOTER_MAX_LENGTH = 56;

    /** @var array<string, int> */
    private const array MAX_LENGTHS = [
        'campaign_name' => self::CAMPAIGN_NAME_MAX_LENGTH,
        'promo_code' => self::PROMO_CODE_MAX_LENGTH,
        'template_id' => self::TEMPLATE_ID_MAX_LENGTH,
        'header' => self::HEADER_MAX_LENGTH,
        'body' => self::BODY_MAX_LENGTH,
        'picture_url' => self::PICTURE_URL_MAX_LENGTH,
        'footer' => self::FOOTER_MAX_LENGTH,
    ];

    /** @var list<string> */
    private const array BOOLEAN_FIELDS = [
        'enabled',
        'campaign_recurrent',
        'cheapest_product_free',
        'only_pickup',
        'only_delivery',
        'only_newcustomers',
        'only_oncepercustomer',
        'only_onceperdaypercustomer',
        'only_loyalcustomers',
        'only_oneproductpercustomer',
        'init_message_to_clients',
        'primal',
        'mon',
        'tue',
        'wed',
        'thu',
        'fri',
        'sat',
        'sun',
        'pickup_mon',
        'pickup_tue',
        'pickup_wed',
        'pickup_thu',
        'pickup_fri',
        'pickup_sat',
        'pickup_sun',
    ];

    /** @var list<string> */
    private const array INT_FIELDS = [
        'max_clients_reach',
        'max_orders',
        'campaign_type',
        'discount_percentage',
        'free_product_id',
        'min_items_in_cart',
        'items_package',
        'pos_item_free',
        'campaign_config_id',
    ];

    /** @var list<string> */
    private const array NUMERIC_FIELDS = ['cost', 'discount_price', 'min_cart_total', 'bundle_price'];

    /** @var list<string> */
    private const array JSON_FIELDS = ['slots', 'metadata'];

    /** Human-readable label per field, for client-facing error text. */
    private const array FIELD_LABELS = [
        'campaign_name' => 'Campaign name',
        'enabled' => 'Enabled status',
        'promo_code' => 'Promo code',
        'template_id' => 'Template',
        'header' => 'Header text',
        'body' => 'Message body',
        'picture_url' => 'Picture URL',
        'footer' => 'Footer text',
        'cost' => 'Cost',
        'discount_price' => 'Discount price',
        'min_cart_total' => 'Minimum cart total',
        'bundle_price' => 'Bundle price',
        'discount_percentage' => 'Discount percentage',
        'free_product_id' => 'Free product',
        'min_items_in_cart' => 'Minimum items in cart',
        'items_package' => 'Items package size',
        'pos_item_free' => 'Free item position',
        'campaign_config_id' => 'Campaign configuration',
        'campaign_type' => 'Campaign type',
        'max_clients_reach' => 'Maximum clients reached',
        'max_orders' => 'Maximum orders',
        'slots' => 'Time slots',
        'metadata' => 'Extra details',
    ];

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
        private readonly ValidationService $validationService,
    ) {}

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
        $this->validatePayload($request->body, requireAll: true);

        return $this->call('campaigns_create', ['shop_id' => $shopId], $request->body);
    }

    public function updateCampaign(Request $request, string $shopId, string $id): Response
    {
        $shopId = $this->requireShopId($shopId);
        $id = $this->requirePositiveInt($id, 'id');
        $this->validatePayload($request->body, requireAll: false);

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

    /**
     * @param array<string, mixed> $body
     */
    private function validatePayload(array $body, bool $requireAll): void
    {
        if ($requireAll) {
            $this->validationService->required($body, 'campaign_name', $this->label('campaign_name'));
            $this->validationService->requiredPresence($body, 'enabled', $this->label('enabled'));
        }

        foreach (self::MAX_LENGTHS as $field => $max) {
            $this->validationService->maxLength($body, $field, $max, $this->label($field));
        }

        foreach (self::NUMERIC_FIELDS as $field) {
            $this->validationService->numeric($body, $field, $this->label($field));
        }

        foreach (self::INT_FIELDS as $field) {
            $this->validationService->integer($body, $field, $this->label($field));
        }

        foreach (self::BOOLEAN_FIELDS as $field) {
            $this->validationService->boolean($body, $field, $this->label($field));
        }

        foreach (self::JSON_FIELDS as $field) {
            $this->validationService->json($body, $field, $this->label($field));
        }

        $hasErrors = $this->validationService->hasErrors();
        $technical = $this->validationService->technicalErrors();
        $friendly = $this->validationService->friendlyErrors();
        $this->validationService->reset();

        if ($hasErrors) {
            $this->fail(
                new ValidationException($friendly, 'Some of the information provided is invalid.'),
                ['errors' => $technical, 'body' => $body],
            );
        }
    }

    private function label(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
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
