<?php

declare(strict_types=1);

namespace Plugins\Orders\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

/**
 * Public orders endpoints, proxied to orders.ms through the function_map.
 *
 * Identifiers (shop_id) go in the path; personal data (phone numbers) goes in
 * the JSON body so it never lands in access logs.
 */
final class OrdersController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

    /** GET /orders/{shop_id}/active */
    public function listActive(string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);

        return $this->call('orders_list_active', $shopId);
    }

    /** POST /orders/{shop_id}/client/usual  body: {"phone": "..."} */
    public function usualForClient(Request $request, string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);
        $phone = $this->requirePhone($request);

        return $this->call('orders_usual_for_client', $shopId, ['phonenumber' => $phone]);
    }

    /** POST /orders/{shop_id}/client/reorder  body: {"phone": "...", "hash": "..."} */
    public function reorder(Request $request, string $shopId): Response
    {
        $shopId = $this->requireShopId($shopId);
        $phone = $this->requirePhone($request);
        $hash = $this->requireField((string) ($request->body['hash'] ?? ''), 'hash');

        return $this->call('orders_reorder', $shopId, ['phonenumber' => $phone, 'hash' => $hash]);
    }

    /** @param array<string, mixed> $payload JSON body sent to orders.ms */
    private function call(string $function, int $shopId, array $payload = []): Response
    {
        try {
            $response = $this->serviceClient->call($function, ['shop_id' => $shopId], $payload);
        } catch (ServiceException $ex) {
            $this->logger->error("order_service: '{$function}' call failed", [
                'function' => $function,
                'shop_id' => $shopId,
                'exception' => $ex::class,
                'error' => $ex->getMessage(),
                'previous' => $ex->getPrevious()?->getMessage(),
                'file' => $ex->getFile() . ':' . $ex->getLine(),
            ]);
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

    private function requirePhone(Request $request): string
    {
        return $this->requireField((string) ($request->body['phone'] ?? ''), 'phone');
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
