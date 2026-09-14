<?php

declare(strict_types=1);

namespace Plugins\Cart\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

/**
 * Thin gateway over the cart microservice's own cart_update/cart_checkout/
 * cart_get functions (see .claude/cart.md) — same shape as Orders/Clients/
 * Shops/Categories: validate just enough to fail fast, forward the rest via
 * ServiceClient, relay whatever comes back. No local business logic.
 *
 * Runs behind CartPlugin's Pmsrapi\V2\Support\ShopContext::wrap(), so shop_id
 * is already validated as a route param; $shopId here is that same value.
 */
final class CartController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

    /** POST /cart/{shop_id}/update */
    public function update(Request $request): Response
    {
        $this->requireNonEmptyString($request->body, 'phonenumber');
        $this->requireArray($request->body, 'items');

        return $this->call('cart_update', $request->body);
    }

    /** POST /cart/{shop_id}/checkout */
    public function checkout(Request $request): Response
    {
        $this->requireNonEmptyString($request->body, 'phonenumber');
        $this->requireArray($request->body, 'checkout_data');

        return $this->call('cart_checkout', $request->body);
    }

    /** POST /cart/{shop_id}/get */
    public function get(Request $request, string $phone): Response
    {
        if( trim($phone) === "" ){
            throw new ValidationException(["invalid phone" => "Invalid phone number provided"]);
        }
        
        $this->requireNonEmptyString($request->body, 'phonenumber');

        return $this->call('cart_get', $request->body);
    }

    /** @param array<string, mixed> $body */
    private function requireNonEmptyString(array $body, string $field): void
    {
        if (trim((string) ($body[$field] ?? '')) === '') {
            throw new ValidationException([$field => "{$field} is required"]);
        }
    }

    /** @param array<string, mixed> $body */
    private function requireArray(array $body, string $field): void
    {
        if (!isset($body[$field]) || !is_array($body[$field])) {
            throw new ValidationException([$field => "{$field} is required and must be an array/object"]);
        }
    }

    /** @param array<string, mixed> $data */
    private function call(string $function, array $data): Response
    {
        try {
            $response = $this->serviceClient->call($function, [], [
                'shop_id' => shop_id,
                'data' => $data,
            ]);
        } catch (ServiceException $ex) {
            $this->logger->error("cart_service: '{$function}' call failed", [
                'function' => $function,
                'shop_id' => shop_id,
                'exception' => $ex::class,
                'error' => $ex->getMessage(),
                'previous' => $ex->getPrevious()?->getMessage(),
                'file' => $ex->getFile() . ':' . $ex->getLine(),
            ]);
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok($response);
    }
}
