<?php

declare(strict_types=1);

use Plugins\Users\Webhooks\UsersWebhookHandler;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

$container = require __DIR__ . '/v2/bootstrap.php';
$config = $container->get(Config::class);

try {
    $response = $container->get(UsersWebhookHandler::class)->handle(Request::fromGlobals());
} catch (\Throwable $e) {
    if (!$e instanceof ApiException) {
        $container->get(Logger::class)->critical('Unhandled exception in users-events.php', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }
    $response = Response::fromThrowable($e, $config->isProduction());
}

$response->send();
