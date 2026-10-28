<?php

declare(strict_types=1);

namespace Plugins\CompanyWebhooks;

use Plugins\CompanyWebhooks\Controllers\CompanyWebhooksController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\Logger;

// The events.ms CompanyWebhooks routes under /v2/companywebhooks/…
// ("webhooks" is a core-reserved prefix, same as on events.ms).
final class CompanyWebhooksPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            CompanyWebhooksController::class,
            static fn(Container $c): CompanyWebhooksController => new CompanyWebhooksController(
                $c->get(ServiceClient::class),
                $c->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/event-types', static fn(Request $request, array $params): Response
            => $container->get(CompanyWebhooksController::class)->listEventTypes());

        $router->get('/{company_id}/webhooks', static fn(Request $request, array $params): Response
            => $container->get(CompanyWebhooksController::class)->list($params['company_id']));

        $router->post('/{company_id}/webhooks', static fn(Request $request, array $params): Response
            => $container->get(CompanyWebhooksController::class)->add($request, $params['company_id']));

        $router->get('/{company_id}/webhooks/{webhook_id}', static fn(Request $request, array $params): Response
            => $container->get(CompanyWebhooksController::class)->get($params['company_id'], $params['webhook_id']));

        $router->put('/{company_id}/webhooks/{webhook_id}', static fn(Request $request, array $params): Response
            => $container->get(CompanyWebhooksController::class)->update($request, $params['company_id'], $params['webhook_id']));

        $router->delete('/{company_id}/webhooks/{webhook_id}', static fn(Request $request, array $params): Response
            => $container->get(CompanyWebhooksController::class)->delete($request, $params['company_id'], $params['webhook_id']));

        // ?webhook_id=&page=&per_page=
        $router->get('/{company_id}/events', static fn(Request $request, array $params): Response
            => $container->get(CompanyWebhooksController::class)->listEvents($request, $params['company_id']));

        $router->post('/{company_id}/events/{event_id}/resend', static fn(Request $request, array $params): Response
            => $container->get(CompanyWebhooksController::class)->resend($params['company_id'], $params['event_id']));
    }
}
