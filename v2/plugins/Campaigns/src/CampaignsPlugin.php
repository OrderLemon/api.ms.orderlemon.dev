<?php

declare(strict_types=1);

namespace Plugins\Campaigns;

use Plugins\Campaigns\Controllers\CampaignsController;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Support\ShopContext;

final class CampaignsPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            CampaignsController::class,
            static fn(Container $container): CampaignsController => new CampaignsController(
                $container->get(ServiceClient::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get(
            '/{shop_id}',
            ShopContext::wrap(
                static fn(Request $request, array $params): Response
                    => $container->get(CampaignsController::class)->getCampaigns($params['shop_id']),
            ),
        );
    }
}
