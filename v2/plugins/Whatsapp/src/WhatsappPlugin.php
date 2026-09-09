<?php

declare(strict_types=1);

namespace Plugins\Whatsapp;

use Pmsrapi\V2\Cluster\ServiceClient;
use Plugins\Whatsapp\Controllers\WhatsappController;
use Plugins\Whatsapp\Support\WhatsappAiClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Support\Logger;

/**
 * WhatsApp inbound receiver — a pure pass-through. See ../SPLIT_PLAN.md.
 *
 * This service is the "client webhook" the WhatsApp gateway
 * (api.wa.fabulor.io) forwards to. Its send_payload_to_client() POSTs an
 * { "a": "incoming", ... } envelope to the URL in the account's
 * `accounts.webhook` column, which for this project is this repo:
 *
 *   POST /v2/whatsapp   { "a": "incoming", "phonenumber": "<account>", ... }
 *
 * Bearer-authenticated like every other core endpoint. This service has no
 * database and no local state — it validates the envelope shape, forwards it
 * to the "AI + gateway" microservice, and relays back what happened. There is
 * no plugin-specific health check here (nothing local to check); the core's
 * generic /v2/health covers this service's own liveness.
 */
final class WhatsappPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(WhatsappAiClient::class, static fn(Container $c): WhatsappAiClient => new WhatsappAiClient(
            $c->get(ServiceClient::class),
        ));

        $registrar->singleton(
            WhatsappController::class,
            static fn(Container $c): WhatsappController => new WhatsappController(
                $c->get(WhatsappAiClient::class),
                $c->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->post('/', static fn(Request $request, array $params): Response
            => $container->get(WhatsappController::class)->receive($request));
    }
}
