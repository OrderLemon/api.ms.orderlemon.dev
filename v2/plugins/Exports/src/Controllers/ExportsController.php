<?php

declare(strict_types=1);

namespace Plugins\Exports\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\NotFoundException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

/**
 * Checks the request's shape here (shop_id, a well-formed type, a known file
 * type) so a malformed request never reaches dataport.ms. Which file types a
 * given export type allows, and whether the type exists, is dataport's call;
 * {@see call()} turns its errors into friendly messages for the shop owner,
 * while the technical version is logged.
 */
final class ExportsController
{
    /** Every file type dataport can write; each export type may allow fewer. */
    private const array FILE_TYPES = ['csv', 'xlsx'];

    private const string TYPE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

    public function createExport(Request $request, string $shopId, string $type): Response
    {
        $shopId = $this->requireShopId($shopId);
        $type = $this->requireType($type);
        $format = $this->requireFormat($request->body['format'] ?? 'csv');

        return $this->call('exports_create', ['type' => $type], ['shop_id' => $shopId, 'format' => $format]);
    }

    private function call(string $function, array $params, array $payload): Response
    {
        try {
            $response = $this->serviceClient->call($function, $params, $payload);
        } catch (ServiceException $ex) {
            $this->logger->error('Proxied call failed', [
                'function' => $function,
                'status' => $ex->statusCode(),
                'message' => $ex->getMessage(),
                ...$params,
                ...$payload,
            ]);

            throw match ($ex->statusCode()) {
                404 => new NotFoundException('That export is not available.'),
                422 => new ValidationException([], 'This export could not be created with the options provided.'),
                429 => new ServiceException(
                    'Too many requests — please wait a moment and try again.',
                    429,
                    'rate_limited',
                ),
                default => new ServiceException(
                    'Something went wrong creating the export. Please try again.',
                    $ex->statusCode() >= 500 ? 502 : $ex->statusCode(),
                ),
            };
        }

        return Response::created($response);
    }

    private function requireShopId(string $value): int
    {
        $shopId = filter_var($value, FILTER_VALIDATE_INT);

        if ($shopId === false || $shopId <= 0) {
            $this->fail(new ValidationException(['shop_id' => 'A valid shop_id is required']), ['shop_id' => $value]);
        }

        return $shopId;
    }

    private function requireType(string $value): string
    {
        $type = strtolower($value);

        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            $this->fail(new NotFoundException('That export is not available.'), ['type' => $value]);
        }

        return $type;
    }

    private function requireFormat(mixed $value): string
    {
        $format = is_string($value) ? strtolower($value) : null;

        if (!in_array($format, self::FILE_TYPES, true)) {
            $this->fail(
                new ValidationException(['format' => 'File type must be one of: ' . implode(', ', self::FILE_TYPES)]),
                ['format' => $value],
            );
        }

        return $format;
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
