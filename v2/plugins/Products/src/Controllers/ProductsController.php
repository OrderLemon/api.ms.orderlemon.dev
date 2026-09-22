<?php

declare(strict_types=1);

namespace Plugins\Products\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class ProductsController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
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

        return $this->call('products_create', ['shop_id' => $shopId], $request->body);
    }

    public function updateProduct(Request $request, string $shopId, string $id): Response
    {
        $shopId = $this->requireShopId($shopId);
        $id = $this->requirePositiveInt($id, 'id');

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
