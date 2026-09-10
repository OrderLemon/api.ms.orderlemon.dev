<?php

declare(strict_types=1);

namespace Plugins\Orders\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class OrdersController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    /** GET /orders/{shop_id}/list */
    public function listActive(string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);

        return $this->call('orders_list_active', ['shop_id' => $shopId]);
    }

    /** GET /orders/{shop_id}/usual/{phone} */
    public function usualForClient(string $shopId, string $phone): Response
    {
        $shopId = $this->requireShopId($shopId);
        $phone = $this->requireField($phone, 'phone');

        return $this->call('orders_usual_for_client', [
            'shop_id' => $shopId,
            'data' => ['phone' => $phone],
        ]);
    }

    /** POST /orders/{shop_id}/reorder */
    public function reorder(Request $request, string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);
        $phone = $this->requireField((string) ($request->body['phone'] ?? ''), 'phone');
        $hash = $this->requireField((string) ($request->body['hash'] ?? ''), 'hash');

        return $this->call('orders_reorder', [
            'shop_id' => $shopId,
            'data' => ['phone' => $phone, 'hash' => $hash],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function call(string $function, array $payload): Response
    {
        try {
            $response = $this->serviceClient->call($function, [], $payload);
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

    private function requireField(string $value, string $name): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new ValidationException([$name => "{$name} is required"]);
        }

        return $value;
    }
}
