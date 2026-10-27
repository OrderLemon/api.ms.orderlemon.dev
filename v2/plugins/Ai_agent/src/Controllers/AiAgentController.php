<?php

declare(strict_types=1);

namespace Plugins\Ai_agent\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

// Thin proxy: shop.ms validates the bodies. Calls go through
// ServiceClient::stream() so shop.ms's own error (code, message, field
// errors) reaches the client; call() would keep only the HTTP status.
final class AiAgentController
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

    public function get(string $shopId): Response
    {
        return $this->call('ai_agent_get', ['shop_id' => $this->requireId($shopId)]);
    }

    public function save(Request $request, string $shopId): Response
    {
        return $this->call('ai_agent_update', ['shop_id' => $this->requireId($shopId)], $request->body);
    }

    public function createItem(Request $request, string $shopId): Response
    {
        return $this->call('ai_agent_item_create', ['shop_id' => $this->requireId($shopId)], $request->body);
    }

    public function updateItem(Request $request, string $shopId): Response
    {
        return $this->call('ai_agent_item_update', ['shop_id' => $this->requireId($shopId)], $request->body);
    }

    public function deleteItem(Request $request, string $shopId): Response
    {
        return $this->call('ai_agent_item_delete', ['shop_id' => $this->requireId($shopId)], $request->body);
    }

    private function call(string $function, array $params, array $payload = []): Response
    {
        $envelope = null;
        foreach ($this->serviceClient->stream($function, $params, $payload) as $record) {
            $envelope = $record;
            break; // exactly one record expected: the whole response body.
        }

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

    private function requireId(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false || $id <= 0) {
            $this->logger->warning('Rejected request', ['error_code' => 'validation_failed', 'shop_id' => $value]);

            throw new ValidationException(['shop_id' => 'A valid shop_id is required']);
        }

        return $id;
    }
}
