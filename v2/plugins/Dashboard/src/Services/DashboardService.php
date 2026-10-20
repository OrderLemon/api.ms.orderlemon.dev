<?php

declare(strict_types=1);

namespace Plugins\Dashboard\Services;

use Pmsrapi\V2\Cluster\ServiceClient;

final class DashboardService
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    public function forShop(int $shopId, string $from, string $to): array
    {
        return $this->serviceClient->call('analytics_dashboard', [
            'shop_id' => $shopId,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function forProduct(int $shopId, int $productId, string $from, string $to): array
    {
        return $this->serviceClient->call('analytics_product', [
            'product_id' => $productId,
            'shop_id' => $shopId,
            'from' => $from,
            'to' => $to,
        ]);
    }
}
