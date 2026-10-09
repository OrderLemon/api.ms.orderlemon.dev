<?php

declare(strict_types=1);

namespace Plugins\Imports\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ConflictException;
use Pmsrapi\V2\Exception\NotFoundException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;


final class ImportsController
{
    private const string TYPE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const int MAX_FILE_LENGTH = 7_000_000;

    private const int FILE_NAME_MAX_LENGTH = 255;

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

    public function createImport(Request $request, string $shopId, string $type): Response
    {
        $shopId = $this->requirePositiveInt($shopId, 'shop_id');
        $type = $this->requireType($type);
        $body = $request->body;

        $file = $body['file'] ?? null;
        if (!is_string($file) || $file === '') {
            $this->fail(new ValidationException(['file' => 'Please choose a file to import.']));
        }
        if (strlen($file) > self::MAX_FILE_LENGTH) {
            $this->fail(new ValidationException(['file' => 'The file may be at most 5 MB.']));
        }

        $fileName = $body['file_name'] ?? null;
        if ($fileName !== null && (!is_string($fileName) || mb_strlen($fileName) > self::FILE_NAME_MAX_LENGTH)) {
            $this->fail(new ValidationException(['file_name' => 'The file name is not valid.']));
        }

        $dryRun = filter_var($body['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($dryRun === null) {
            $this->fail(new ValidationException(['dry_run' => 'dry_run must be true or false']));
        }

        $result = $this->forward('imports_create', ['type' => $type], [
            'shop_id' => $shopId,
            'file' => $file,
            'file_name' => $fileName,
            'dry_run' => $dryRun,
        ]);

        return isset($result['import_id']) ? Response::created($result) : Response::ok($result);
    }

    public function getImport(string $shopId, string $type, string $importId): Response
    {
        $shopId = $this->requirePositiveInt($shopId, 'shop_id');
        $type = $this->requireType($type);
        $importId = $this->requirePositiveInt($importId, 'import_id');

        return Response::ok($this->forward('imports_get', [
            'type' => $type,
            'import_id' => $importId,
            'shop_id' => $shopId,
        ]));
    }

    private function forward(string $function, array $params, array $payload = []): array
    {
        $envelope = null;
        try {
            foreach ($this->serviceClient->stream($function, $params, $payload) as $record) {
                $envelope = $record;
                break;
            }
        } catch (ServiceException $ex) {
            $this->logger->error('Proxied call failed', [
                'function' => $function,
                'status' => $ex->statusCode(),
                'message' => $ex->getMessage(),
                ...$params,
            ]);

            throw new ServiceException('Something went wrong with the import. Please try again.', 502);
        }

        if (($envelope['success'] ?? null) === true) {
            return is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        }

        $error = is_array($envelope['error'] ?? null) ? $envelope['error'] : [];
        $this->logger->error('Proxied call failed', [
            'function' => $function,
            'error' => $error ?: 'no response',
            ...$params,
        ]);

        throw match ($error['code'] ?? null) {
            // dataport's validation messages are written for the shop owner: passed on as they are.
            'validation_failed' => new ValidationException(
                (array) ($error['details']['errors'] ?? []),
                (string) ($error['message'] ?? 'The file has errors.'),
            ),
            'not_found' => new NotFoundException('That import is not available.'),
            'import_in_progress' => new ConflictException('An import is already running for this shop. Please wait for it to finish.'),
            'imports_unavailable' => new ConflictException('Imports are not available for this shop.'),
            'rate_limited' => new ServiceException('Too many requests — please wait a moment and try again.', 429, 'rate_limited'),
            default => new ServiceException('Something went wrong with the import. Please try again.', 502),
        };
    }

    private function requirePositiveInt(string $value, string $field): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);

        if ($int === false || $int <= 0) {
            $this->fail(new ValidationException([$field => "A valid {$field} is required"]), [$field => $value]);
        }

        return $int;
    }

    private function requireType(string $value): string
    {
        $type = strtolower($value);

        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            $this->fail(new NotFoundException('That import is not available.'), ['type' => $value]);
        }

        return $type;
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
