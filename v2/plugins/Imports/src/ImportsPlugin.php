<?php

declare(strict_types=1);

namespace Plugins\Imports;

use Plugins\Imports\Controllers\ImportsController;
use Plugins\Imports\Controllers\WebsiteController;
use Plugins\Imports\Website\ScrapeJobContext;
use Plugins\Imports\Website\ScraperClient;
use Plugins\Imports\Website\ScraperWebhookHandler;
use Pmsrapi\V2\Cache\RateLimiter;
use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Queue\WebhookDispatcher;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Support\ShopContext;
use Plugins\Auth\Access\EndpointSecurity;

/**
 *   POST /v2/imports/{shop_id}/{type}               body {"file": "<base64>", "file_name": "x.xlsx", "dry_run": true}
 *   GET  /v2/imports/{shop_id}/{type}/{import_id}   status of a queued import
 *
 * Forwarded to dataport.ms as function_map entries imports_create and
 * imports_get. A dry run returns what the import would do ({create, delete});
 * otherwise the import is queued and 201 {import_id, ...} is returned. Row
 * errors in the file come back as 422 with {row, column, message} errors.
 *
 * Import from a website (the scraper, ol_api_scrapy; see WebsiteController):
 *   POST   /v2/imports/website/{shop_id}/detect
 *   POST   /v2/imports/website/{shop_id}/jobs
 *   GET    /v2/imports/website/{shop_id}/jobs/{job_id}
 *   DELETE /v2/imports/website/{shop_id}/jobs/{job_id}
 *   GET    /v2/imports/website/{shop_id}/jobs/{job_id}/results
 *   GET    /v2/imports/website/{shop_id}/jobs/{job_id}/diff
 * and its "job finished" webhook, POST /v2/webhooks/scraper (v2/.htaccess →
 * hooks/scraper.php, ScraperWebhookHandler).
 */
final class ImportsPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            ImportsController::class,
            static fn(Container $container): ImportsController => new ImportsController(
                $container->get(ServiceClient::class),
                $container->get(Logger::class),
                $container->get(EndpointSecurity::class),
            ),
        );

        $registrar->singleton(
            ScraperClient::class,
            static fn(Container $c): ScraperClient => new ScraperClient(
                $c->get(Config::class),
                $c->get(Logger::class),
            ),
        );

        $registrar->singleton(
            ScrapeJobContext::class,
            static fn(Container $c): ScrapeJobContext => new ScrapeJobContext(
                $c->get(RedisClient::class),
                $c->get(Logger::class),
            ),
        );

        $registrar->singleton(
            WebsiteController::class,
            static fn(Container $c): WebsiteController => new WebsiteController(
                $c->get(ScraperClient::class),
                $c->get(ScrapeJobContext::class),
                $c->get(EndpointSecurity::class),
                $c->get(Logger::class),
            ),
        );

        $registrar->singleton(
            ScraperWebhookHandler::class,
            static fn(Container $c): ScraperWebhookHandler => new ScraperWebhookHandler(
                $c->get(ScraperClient::class),
                $c->get(ScrapeJobContext::class),
                $c->get(WebhookDispatcher::class),
                $c->get(RateLimiter::class),
                $c->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->post(
            '/{shop_id}/{type}',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(ImportsController::class)->createImport(
                        $request,
                        $params['shop_id'],
                        $params['type'],
                    ),
            ),
        );

        $router->get(
            '/{shop_id}/{type}/{import_id}',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(ImportsController::class)->getImport(
                        $request,
                        $params['shop_id'],
                        $params['type'],
                        $params['import_id'],
                    ),
            ),
        );

        // Website import. Segment counts differ from the file-import routes above
        // (POST 2, GET 3), so neither set can shadow the other.
        $website = static fn(): WebsiteController => $container->get(WebsiteController::class);

        $router->post(
            '/website/{shop_id}/detect',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $website()->detect($request, $params['shop_id']),
            ),
        );

        $router->post(
            '/website/{shop_id}/jobs',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $website()->createJob($request, $params['shop_id']),
            ),
        );

        $router->get(
            '/website/{shop_id}/jobs/{job_id}',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $website()->getJob($request, $params['shop_id'], $params['job_id']),
            ),
        );

        $router->delete(
            '/website/{shop_id}/jobs/{job_id}',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $website()->cancelJob($request, $params['shop_id'], $params['job_id']),
            ),
        );

        $router->get(
            '/website/{shop_id}/jobs/{job_id}/results',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $website()->results($request, $params['shop_id'], $params['job_id']),
            ),
        );

        $router->get(
            '/website/{shop_id}/jobs/{job_id}/diff',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $website()->diff($request, $params['shop_id'], $params['job_id']),
            ),
        );
    }
}
