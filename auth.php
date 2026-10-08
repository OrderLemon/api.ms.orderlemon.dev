<?php

declare(strict_types=1);

/**
 * Public sign-in entry point for the frontend.
 *
 * It lives outside the v2 front controller, so the core's Bearer check (service token /
 * TokenStore) doesn't apply here. Access is controlled by short-lived signed guest tokens
 * and per-IP rate limits instead. All logic is in the Auth plugin (GuestAuthHandler);
 * this file only boots the v2 container, like v2/index.php does.
 *
 *   POST /auth.php?a=session                 → {token, expires_at, config}
 *   POST /auth.php?a=apple|google|password   (Authorization: Bearer <guest token>) → {user, token}
 *
 * See v2/plugins/Auth/README.md.
 */

use Plugins\Auth\Guest\GuestAuthHandler;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

$container = require __DIR__ . '/v2/bootstrap.php';
$config = $container->get(Config::class);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        $response = Response::noContent(); // CORS preflight
    } else {
        $response = $container->get(GuestAuthHandler::class)->handle(
            Request::fromGlobals(),
            is_string($_GET['a'] ?? null) ? $_GET['a'] : '',
        );
    }
} catch (\Throwable $e) {
    if (!$e instanceof ApiException) {
        $container->get(Logger::class)->critical('Unhandled exception in auth.php', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }
    $response = Response::fromThrowable($e, $config->isProduction());
}

foreach ((array) $config->public('headers', []) as $name => $value) {
    $response = $response->withHeader((string) $name, (string) $value);
}

$response->send();
