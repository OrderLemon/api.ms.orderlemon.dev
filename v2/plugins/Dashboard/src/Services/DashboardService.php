<?php

declare(strict_types=1);

namespace Plugins\Dashboard\Services;

use Pmsrapi\V2\Cluster\ServiceClient;

final class DashboardService
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    public function forShop(int $shopId, int $interval): array
    {
        return $this->serviceClient->call('analytics_dashboard', [
            'shop_id' => $shopId,
            'interval' => $interval,
        ]);
    }
}
