<?php

declare(strict_types=1);

namespace Plugins\Categories\Controllers;

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

/**
 * Payload-shape checks in {@see validatePayload()} mirror products.ms's own
 * CategoriesController validation (name/enabled required on create, name's
 * column-width limit, boolean/JSON field checks) so a malformed request is
 * rejected here before it ever reaches the proxied call.
 *
 * Failures that depend on products.ms's state instead (is the name a
 * duplicate, does the id exist) can't be caught this way — {@see call()}
 * translates the resulting ServiceException's status into the matching
 * existing exception (NotFoundException, ValidationException,
 * ConflictException, ...) with a friendly message instead, rather than a
 * bespoke type per status.
 *
 * Every error shown to the caller is deliberately non-technical (field
 * labels, not raw column names) since the caller here is ultimately a shop
 * owner, not a developer — the precise, technical version of every
 * rejection is logged via {@see fail()} instead.
 */
final class CategoriesController
{
    private const int NAME_MAX_LENGTH = 24;

    /** Human-readable label per field, for client-facing error text. */
    private const array FIELD_LABELS = [
        'name' => 'Name',
        'enabled' => 'Enabled status',
        'is_modifier' => 'Modifier flag',
        'metadata' => 'Extra details',
    ];

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
        private readonly ValidationService $validationService,
    ) {}

    public function getCategories(string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);

        return $this->call('categories_get', ['shop_id' => $shopId]);
    }

    public function getCategory(string $shopId, string $id): Response
    {
        $shopId = $this->requireShopId($shopId);
        $id = $this->requirePositiveInt($id, 'id');

        return $this->call('categories_get_category', ['shop_id' => $shopId, 'id' => $id]);
    }

    public function createCategory(Request $request, string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);
        $this->validatePayload($request->body, requireAll: true);

        return $this->call('categories_create', ['shop_id' => $shopId], $request->body);
    }

    public function updateCategory(Request $request, string $shopId, string $id): Response
    {
        $shopId = $this->requireShopId($shopId);
        $id = $this->requirePositiveInt($id, 'id');
        $this->validatePayload($request->body, requireAll: false);

        return $this->call('categories_update', ['shop_id' => $shopId, 'id' => $id], $request->body);
    }

    public function deleteCategory(string $shopId, string $id): Response
    {
        $shopId = $this->requireShopId($shopId);
        $id = $this->requirePositiveInt($id, 'id');

        return $this->call('categories_delete', ['shop_id' => $shopId, 'id' => $id]);
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
                404 => new NotFoundException('That category could not be found.'),
                409 => new ConflictException('A category with that name already exists.'),
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
        $this->validationService->boolean($body, 'enabled', $this->label('enabled'));
        $this->validationService->boolean($body, 'is_modifier', $this->label('is_modifier'));
        $this->validationService->json($body, 'metadata', $this->label('metadata'));

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

    /**
     * Every validation failure passes through here so the precise, technical
     * reason is always logged server-side — the exception itself only ever
     * carries the friendly, client-facing wording.
     *
     * @param array<string, mixed> $context
     */
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
