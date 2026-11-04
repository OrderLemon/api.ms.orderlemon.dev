<?php

declare(strict_types=1);

// Nightly retention job (cron): clear content, archive, purge. Spec §11.

use Plugins\Notifications\Retention\RetentionService;
use Pmsrapi\V2\Support\Logger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const WORKER_SUFFIX = "orderlemon.dev";

$container = require dirname(__DIR__, 2) . '/bootstrap.php';

$config = $container->get(Pmsrapi\V2\Plugin\Config::class);
$logger = $container->get(Pmsrapi\V2\Plugin\Logger::class);


try {
    
    $workerNames = $config->secret("webhook_workers", []);
    if( $workerNames === []){
        $logger->info('No workers required.');
        exit();
    }
    
    foreach($workerNames as $workerName){
        $workerPath = $workerName . "." . WORKER_SUFFIX;
    }
    
    $workerPath = dirname(__DIR__, 3) . $workerPath . "/v2/worker.php";
    
    if(!file_exists($workerPath)){
        $logger->error('Worker file path not found!', ["worker" => $workerName, "path" => $workerPath]);
    }
    
    
    
    $logger->info('Notifications retention finished', $result);
    fwrite(STDOUT, json_encode($result) . "\n");
} catch (\Throwable $e) {
    $logger->error('Notifications retention failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
