<?php

declare(strict_types=1);

namespace Plugins\Payment_methods\Services;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Support\Logger;

/**
 * Calls payway.ms and returns its `data`, or rethrows its error with the right status.
 *
 * Uses ServiceClient::stream() rather than call(): call() drops the error body on 4xx, and the
 * panel needs payway's error code and validation details. stream() doesn't expose the HTTP
 * status, so the status comes from the error code (STATUS_BY_ERROR_CODE).
 */
final class PaymentMethodsService
{
    /** payway.ms error code => status returned to the panel. Anything else becomes 502. */
    private const array STATUS_BY_ERROR_CODE = [
        'validation_failed' => 422,
        'not_found' => 404,
        'shop_country_missing' => 409,
        'rate_limited' => 429,
    ];

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, scalar|null> $params
     * @param array<string, mixed>       $payload
     */
    public function call(string $function, array $params, array $payload = []): mixed
    {
        $envelope = null;
        foreach ($this->serviceClient->stream($function, $params, $payload) as $record) {
            $envelope = $record;
            break; // exactly one record expected: the whole response body.
        }

        if (!is_array($envelope) || !array_key_exists('success', $envelope)) {
            $this->logger->error('Empty or non-envelope response from payway.ms', ['function' => $function]);

            throw new ServiceException('Payment service returned no response');
        }

        if ($envelope['success'] !== true) {
            $this->throwFor($function, is_array($envelope['error'] ?? null) ? $envelope['error'] : []);
        }

        return $envelope['data'] ?? null;
    }

    /**
     * @param array<string, mixed> $error
     */
    private function throwFor(string $function, array $error): never
    {
        $code = is_string($error['code'] ?? null) ? $error['code'] : 'service_error';
        $status = self::STATUS_BY_ERROR_CODE[$code] ?? null;

        if ($status === null) {
            // A payway or configuration problem: log the real reason, show the panel a generic failure.
            $this->logger->error('Call to payway.ms failed', ['function' => $function, 'error' => $error]);

            throw new ServiceException('Payment service is temporarily unavailable');
        }

        $message = is_string($error['message'] ?? null) ? $error['message'] : 'Request failed';
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        throw new ApiException($message, $status, $code, $details);
    }
}
