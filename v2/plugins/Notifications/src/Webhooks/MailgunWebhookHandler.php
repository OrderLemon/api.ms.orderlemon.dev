<?php

declare(strict_types=1);

namespace Plugins\Notifications\Webhooks;

use Pmsrapi\V2\Cache\RateLimiter;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ConfigException;
use Pmsrapi\V2\Exception\MethodNotAllowedException;
use Pmsrapi\V2\Exception\RateLimitException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\HttpMethod;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

// POST /v2/plugins/Notifications/hooks/mailgun.php — Mailgun tracking webhooks (delivered, opened,
// clicked, failed, complained, unsubscribed).
// Body: {"signature": {timestamp, token, signature}, "event-data": {…}}.
// Verified here, then `event-data` goes to notifications.ms
// (notifications_tracking_mailgun). Mailgun retries on any non-2xx, so a
// notifications.ms outage surfaces as 503 and the event comes back later.
final class MailgunWebhookHandler
{
    private const int LIMIT = 600; // deliveries per IP per window
    private const int WINDOW_SECONDS = 60;
    private const string FUNCTION = 'notifications_tracking_mailgun';

    public function __construct(
        private readonly Config $config,
        private readonly MailgunSignature $signature,
        private readonly ServiceClient $services,
        private readonly RateLimiter $limiter,
        private readonly Logger $logger,
    ) {}

    public function handle(Request $request): Response
    {
        if ($request->method !== HttpMethod::POST) {
            throw new MethodNotAllowedException(['POST']);
        }

        $limit = $this->limiter->hit('mailgun-webhook:' . $request->ip, self::LIMIT, self::WINDOW_SECONDS);
        if (!$limit->allowed) {
            throw new RateLimitException($limit->retryAfter);
        }

        $this->signature->verify((array) ($request->body['signature'] ?? []), $this->signingKey());

        $eventData = $request->body['event-data'] ?? null;
        if (!is_array($eventData)) {
            throw new ValidationException(['event-data' => 'event-data is required']);
        }

        $result = $this->services->call(self::FUNCTION, payload: $eventData);

        return Response::ok(['result' => $result['result'] ?? null]);
    }

    private function signingKey(): string
    {
        $key = $this->config->secret('notifications.mailgun_signing_key');
        if (!is_string($key) || $key === '') {
            $this->logger->error('notifications.mailgun_signing_key is missing');

            throw new ConfigException('Mailgun webhook is not configured');
        }

        return $key;
    }
}
