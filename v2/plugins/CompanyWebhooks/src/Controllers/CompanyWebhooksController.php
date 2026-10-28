<?php

declare(strict_types=1);

namespace Plugins\CompanyWebhooks\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

// Thin proxy: events.ms validates the bodies. Calls go through
// ServiceClient::stream() so events.ms's own error (code, message,
// field errors) reaches the client; call() would keep only the HTTP status.
final class CompanyWebhooksController
{
    private const array STATUS_BY_ERROR_CODE = [
        'not_found' => 404,
        'conflict' => 409,
        'event_in_progress' => 409,
        'validation_failed' => 422,
        'method_not_allowed' => 405,
        'rate_limited' => 429,
        'database_error' => 500,
        'insert_failed' => 500,
    ];

    private const string EVENT_ID_RE = '/^wh_event_[0-9a-f]{32}$/';

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

    public function listEventTypes(): Response
    {
        return Response::ok($this->call('companywebhooks_event_types', [])['data']);
    }

    public function list(string $companyId): Response
    {
        return Response::ok($this->call('companywebhooks_list', $this->companyParams($companyId))['data']);
    }

    public function get(string $companyId, string $webhookId): Response
    {
        return Response::ok($this->call('companywebhooks_get', $this->webhookParams($companyId, $webhookId))['data']);
    }

    public function add(Request $request, string $companyId): Response
    {
        return Response::created(
            $this->call('companywebhooks_create', $this->companyParams($companyId), $request->body)['data'],
        );
    }

    public function update(Request $request, string $companyId, string $webhookId): Response
    {
        return Response::ok(
            $this->call('companywebhooks_update', $this->webhookParams($companyId, $webhookId), $request->body)['data'],
        );
    }

    public function delete(Request $request, string $companyId, string $webhookId): Response
    {
        $this->call('companywebhooks_delete', $this->webhookParams($companyId, $webhookId), $request->body);

        return Response::noContent();
    }

    public function listEvents(Request $request, string $companyId): Response
    {
        $params = $this->companyParams($companyId);
        foreach (['webhook_id', 'page', 'per_page'] as $key) {
            $value = $request->query($key);
            if ($value !== null) {
                $params[$key] = $this->requireId($value, $key);
            }
        }

        $result = $this->call('companywebhooks_events', $params);

        return Response::ok($result['data'], $result['meta']);
    }

    public function resend(string $companyId, string $eventId): Response
    {
        if (preg_match(self::EVENT_ID_RE, $eventId) !== 1) {
            throw new ValidationException(['event_id' => 'A valid event_id is required']);
        }

        return Response::created(
            $this->call('companywebhooks_resend', [...$this->companyParams($companyId), 'event_id' => $eventId])['data'],
        );
    }

    private function companyParams(string $companyId): array
    {
        return ['company_id' => $this->requireId($companyId, 'company_id')];
    }

    private function webhookParams(string $companyId, string $webhookId): array
    {
        return [
            ...$this->companyParams($companyId),
            'webhook_id' => $this->requireId($webhookId, 'webhook_id'),
        ];
    }

    /**
     * @return array{data: array<mixed>, meta: ?array<string, mixed>}
     */
    private function call(string $function, array $params, array $payload = []): array
    {
        $envelope = null;
        foreach ($this->serviceClient->stream($function, $params, $payload) as $record) {
            $envelope = $record;
            break; // exactly one record expected: the whole response body.
        }

        // 204 from events.ms (delete) has no body.
        if ($envelope === null) {
            return ['data' => [], 'meta' => null];
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

        return [
            'data' => is_array($envelope['data'] ?? null) ? $envelope['data'] : [],
            'meta' => is_array($envelope['meta'] ?? null) ? $envelope['meta'] : null,
        ];
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
