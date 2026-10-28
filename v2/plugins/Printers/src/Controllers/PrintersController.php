<?php

declare(strict_types=1);

namespace Plugins\Printers\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

// Thin proxy: cloudprint.ms validates the bodies. Calls go through
// ServiceClient::stream() so cloudprint.ms's own error (code, message, field
// errors) reaches the panel; call() would keep only the HTTP status.
final class PrintersController
{
    private const array STATUS_BY_ERROR_CODE = [
        'not_found' => 404,
        'validation_failed' => 422,
        'no_active_printers' => 409,
        'printing_not_configured' => 503,
        'rate_limited' => 429,
    ];

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

    public function listForCompany(string $companyId): Response
    {
        return $this->call('printers_list_company', ['company_id' => $this->requireId($companyId, 'company_id')]);
    }

    public function listForShop(string $shopId): Response
    {
        return $this->call('printers_list', ['shop_id' => $this->requireId($shopId, 'shop_id')]);
    }

    public function update(Request $request, string $shopId, string $printerId): Response
    {
        return $this->call('printers_update', $this->printerParams($shopId, $printerId), $request->body);
    }

    public function remove(string $shopId, string $printerId): Response
    {
        return $this->call('printers_delete', $this->printerParams($shopId, $printerId));
    }

    public function test(Request $request, string $shopId, string $printerId): Response
    {
        return $this->call('printers_test', $this->printerParams($shopId, $printerId), $request->body);
    }

    public function printOrder(Request $request, string $shopId, string $orderId): Response
    {
        return $this->call(
            'printers_print_order',
            ['shop_id' => $this->requireId($shopId, 'shop_id'), 'order_id' => $this->requireId($orderId, 'order_id')],
            $request->body,
        );
    }

    private function printerParams(string $shopId, string $printerId): array
    {
        return ['shop_id' => $this->requireId($shopId, 'shop_id'), 'printer_id' => $this->requireId($printerId, 'printer_id')];
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

    private function requireId(string $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false || $id <= 0) {
            $this->logger->warning('Rejected request', ['error_code' => 'validation_failed', $field => $value]);

            throw new ValidationException([$field => "A valid {$field} is required"]);
        }

        return $id;
    }
}
