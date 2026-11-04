<?php

declare(strict_types=1);

namespace Plugins\Notifications\Webhooks;

use Pmsrapi\V2\Cache\RateLimiter;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ConfigException;
use Pmsrapi\V2\Exception\MethodNotAllowedException;
use Pmsrapi\V2\Exception\RateLimitException;
use Pmsrapi\V2\Exception\UnauthorizedException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\HttpMethod;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

// POST /v2/plugins/Notifications/hooks/onesignal.php — OneSignal Event Streams (delivered, clicked,
// failed). OneSignal doesn't sign its webhooks, so the stream is configured
// with our shared secret: header `X-Webhook-Secret`, or `?key=` on the URL
// when the plan doesn't allow custom headers. The body is the JSON template
// we set in OneSignal (spec §8.2) and goes to notifications.ms as is.
final class OneSignalWebhookHandler
{
    private const int LIMIT = 600; // deliveries per IP per window
    private const int WINDOW_SECONDS = 60;
    private const int MIN_SECRET_LENGTH = 32;
    private const string FUNCTION = 'notifications_tracking_onesignal';

    public function __construct(
        private readonly Config $config,
        private readonly ServiceClient $services,
        private readonly RateLimiter $limiter,
        private readonly Logger $logger,
    ) {}

    public function handle(Request $request): Response
    {
        if ($request->method !== HttpMethod::POST) {
            throw new MethodNotAllowedException(['POST']);
        }

        $limit = $this->limiter->hit('onesignal-webhook:' . $request->ip, self::LIMIT, self::WINDOW_SECONDS);
        if (!$limit->allowed) {
            throw new RateLimitException($limit->retryAfter);
        }

        $given = (string) ($request->header('x-webhook-secret') ?? $request->query('key') ?? '');
        if (!hash_equals($this->secret(), $given)) {
            throw new UnauthorizedException('Invalid webhook secret');
        }

        if ($request->body === [] || array_is_list($request->body)) {
            throw new ValidationException(['body' => 'A JSON object is required']);
        }

        $result = $this->services->call(self::FUNCTION, payload: $request->body);

        return Response::ok(['result' => $result['result'] ?? null]);
    }

    private function secret(): string
    {
        $secret = $this->config->secret('notifications.onesignal_webhook_secret');
        if (!is_string($secret) || strlen($secret) < self::MIN_SECRET_LENGTH) {
            $this->logger->error('notifications.onesignal_webhook_secret is missing or shorter than 32 characters');

            throw new ConfigException('OneSignal webhook is not configured');
        }

        return $secret;
    }
}
