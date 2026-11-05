<?php

declare(strict_types=1);

// POST /v2/webhooks/scraper  (rewritten here by v2/.htaccess) — the website scraper's
// "job finished" webhook, signed with scraper.hmac_secret.
// Served directly by Apache (v2/.htaccess serves real files), so the router and the Bearer
// check never run; ScraperWebhookHandler verifies the request itself.

use Plugins\Imports\Website\ScraperWebhookHandler;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

$container = require dirname(__DIR__, 3) . '/bootstrap.php';
$config = $container->get(Config::class);

try {
    // REQUEST_URI is the address the scraper called (before the rewrite): what it signed.
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $response = $container->get(ScraperWebhookHandler::class)->handle(Request::fromGlobals(), $requestUri);
} catch (\Throwable $e) {
    if (!$e instanceof ApiException) {
        $container->get(Logger::class)->critical('Unhandled exception in Imports/hooks/scraper.php', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }
    $response = Response::fromThrowable($e, $config->isProduction());
}

$response->send();
