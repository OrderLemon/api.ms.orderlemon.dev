<?php

declare(strict_types=1);

namespace Plugins\Categories\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class CategoriesController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    public function getCategories(string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);

        return $this->call('categories_get', ['shop_id' => $shopId]);
    }

    public function createCategory(Request $request, string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);

        return $this->call('categories_create', ['shop_id' => $shopId], $request->body);
    }

    public function updateCategory(Request $request, string $shopId, string $id): Response
    {
        $shopId = $this->requireShopId($shopId);
        $id = $this->requirePositiveInt($id, 'id');

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
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok($response);
    }

    private function requireShopId(string $value): int
    {
        $shopId = filter_var($value, FILTER_VALIDATE_INT);

        if ($shopId === false || $shopId <= 0) {
            throw new ValidationException(['shop_id' => 'A valid shop_id is required']);
        }

        return $shopId;
    }

    private function requirePositiveInt(string $value, string $field): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);

        if ($int === false || $int <= 0) {
            throw new ValidationException([$field => "A valid {$field} is required"]);
        }

        return $int;
    }
}
