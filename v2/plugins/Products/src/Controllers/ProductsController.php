<?php

declare(strict_types=1);

namespace Plugins\Products\Controllers;

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


final class ProductsController
{
    private const int NAME_MAX_LENGTH = 200;
    private const int REFERENCE_MAX_LENGTH = 30;

    /** @var list<string> */
    private const array BOOLEAN_FIELDS = ['enabled', 'isstock', 'isfav', 'happy_hour'];

    /** @var list<string> */
    private const array JSON_FIELDS = ['metadata', 'reset_hours', 'reset_days'];

    /** Human-readable label per field, for client-facing error text. */
    private const array FIELD_LABELS = [
        'name' => 'Name',
        'unit_price' => 'Price',
        'enabled' => 'Enabled status',
        'category_id' => 'Category',
        'reference' => 'Reference code',
        'isstock' => 'Stock tracking',
        'isfav' => 'Favorite flag',
        'happy_hour' => 'Happy hour flag',
        'metadata' => 'Extra details',
        'reset_hours' => 'Reset hours',
        'reset_days' => 'Reset days',
    ];

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
        private readonly ValidationService $validationService,
    ) {}

    public function getProducts(string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);

        return $this->call('products_get', ['shop_id' => $shopId]);
    }

    public function getProductsForCategory(string $shopId, string $categoryId): Response
    {
        $shopId = $this->requireShopId($shopId);
        $categoryId = $this->requirePositiveInt($categoryId, 'category_id');

        return $this->call('products_get_for_category', ['shop_id' => $shopId, 'category_id' => $categoryId]);
    }

    public function getProduct(string $shopId, string $id): Response
    {
        $shopId = $this->requireShopId($shopId);
        $id = $this->requirePositiveInt($id, 'id');

        return $this->call('products_get_product', ['shop_id' => $shopId, 'id' => $id]);
    }

    public function createProduct(Request $request, string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);
        $this->validatePayload($request->body, requireAll: true);

        return $this->call('products_create', ['shop_id' => $shopId], $request->body);
    }

    public function updateProduct(Request $request, string $shopId, string $id): Response
    {
        $shopId = $this->requireShopId($shopId);
        $id = $this->requirePositiveInt($id, 'id');
        $this->validatePayload($request->body, requireAll: false);

        return $this->call('products_update', ['shop_id' => $shopId, 'id' => $id], $request->body);
    }

    public function deleteProduct(string $shopId, string $id): Response
    {
        $shopId = $this->requireShopId($shopId);
        $id = $this->requirePositiveInt($id, 'id');

        return $this->call('products_delete', ['shop_id' => $shopId, 'id' => $id]);
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
                404 => new NotFoundException('That product could not be found.'),
                409 => new ConflictException('A product with that reference already exists.'),
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
            $this->validationService->required($body, 'name', $this->label('name'));
            $this->validationService->requiredPresence($body, 'enabled', $this->label('enabled'));
        }

        $this->validationService->maxLength($body, 'name', self::NAME_MAX_LENGTH, $this->label('name'));
        $this->validationService->maxLength($body, 'reference', self::REFERENCE_MAX_LENGTH, $this->label('reference'));
        $this->validationService->numeric($body, 'unit_price', $this->label('unit_price'), required: $requireAll);
        $this->validationService->positiveInt($body, 'category_id', $this->label('category_id'), required: $requireAll);

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
