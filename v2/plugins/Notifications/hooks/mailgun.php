<?php

declare(strict_types=1);

// POST /v2/plugins/Notifications/hooks/mailgun.php — Mailgun tracking webhooks (signed with the Mailgun HTTP webhook signing key).
// Served directly by Apache (v2/.htaccess serves real files), so the router and
// the Bearer check never run; MailgunWebhookHandler verifies the request itself.

use Plugins\Notifications\Webhooks\MailgunWebhookHandler;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

$container = require dirname(__DIR__, 3) . '/bootstrap.php';
$config = $container->get(Config::class);

try {
    $response = $container->get(MailgunWebhookHandler::class)->handle(Request::fromGlobals());
} catch (\Throwable $e) {
    if (!$e instanceof ApiException) {
        $container->get(Logger::class)->critical('Unhandled exception in Notifications/hooks/mailgun.php', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }
    $response = Response::fromThrowable($e, $config->isProduction());
}

$response->send();
