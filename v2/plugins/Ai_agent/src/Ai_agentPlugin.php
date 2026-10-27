<?php

declare(strict_types=1);

namespace Plugins\Ai_agent;

use Plugins\Ai_agent\Controllers\AiAgentController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Support\ShopContext;

// The shop.ms AI agent routes under /v2/ai_agent/…
final class Ai_agentPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            AiAgentController::class,
            static fn(Container $c): AiAgentController => new AiAgentController(
                $c->get(ServiceClient::class),
                $c->get(Logger::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get(
            '/{shop_id}',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(AiAgentController::class)->get($params['shop_id']),
            ),
        );

        $router->put(
            '/{shop_id}',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(AiAgentController::class)->save($request, $params['shop_id']),
            ),
        );

        $router->post(
            '/{shop_id}/items',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(AiAgentController::class)->createItem($request, $params['shop_id']),
            ),
        );

        $router->put(
            '/{shop_id}/items',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(AiAgentController::class)->updateItem($request, $params['shop_id']),
            ),
        );

        $router->delete(
            '/{shop_id}/items',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(AiAgentController::class)->deleteItem($request, $params['shop_id']),
            ),
        );
    }
}
