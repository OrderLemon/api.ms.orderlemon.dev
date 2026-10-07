<?php

declare(strict_types=1);

namespace Plugins\Home\Services;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Support\Logger;

/**
 * Builds the dashboard home from the services' summary endpoints.
 *
 * A service that fails leaves its own fields null instead of failing the whole
 * page, so one slow or broken service doesn't blank the dashboard.
 *
 * Not backed by real data yet:
 *   dashboard.revenue_today  dummy value; will come from a separate service
 *   payouts.next             null; will come from the payments service
 *   payments.methods         online methods are a fixed bancontact/card list
 *                            until the payments service can list them per shop
 */
final class HomeService
{
    private const float DUMMY_REVENUE_TODAY = 486.40;

    /** @var list<string> */
    private const array DUMMY_ONLINE_METHODS = ['bancontact', 'card'];

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function forShop(int $shopId): array
    {
        $orders = $this->summary('orders_summary', $shopId);
        $products = $this->summary('products_summary', $shopId);
        $campaigns = $this->summary('campaigns_summary', $shopId);
        $shop = $this->summary('shop_summary', $shopId);

        $newOrders = $orders['new'] ?? null;
        $openUntil = $shop['hours']['open_until'] ?? null;

        return [
            'accepting_orders' => [
                'enabled' => $shop['accepting_orders']['enabled'] ?? null,
                'open_until' => $openUntil,
                'new_orders' => $newOrders,
            ],
            'dashboard' => ['revenue_today' => self::DUMMY_REVENUE_TODAY],
            'orders' => ['new' => $newOrders],
            'menu' => [
                'total' => $products['total'] ?? null,
                'sold_out' => $products['sold_out'] ?? null,
            ],
            'promotions' => ['active' => $campaigns['active'] ?? null],
            'ai_agent' => ['enabled' => $shop['ai_agent']['enabled'] ?? null],
            'hours' => ['open_until' => $openUntil],
            'logistics' => [
                'pickup' => $shop['logistics']['pickup'] ?? null,
                'delivery' => $shop['logistics']['delivery'] ?? null,
            ],
            'payments' => ['methods' => $this->paymentMethods($shop['payments'] ?? null)],
            'shop' => ['channels' => $shop['channels'] ?? null],
            'payouts' => ['next' => null],
        ];
    }

    /**
     * @param array{cash?: bool, online?: bool}|null $payments
     * @return list<string>|null
     */
    private function paymentMethods(?array $payments): ?array
    {
        if ($payments === null) {
            return null;
        }

        return [
            ...(($payments['online'] ?? false) ? self::DUMMY_ONLINE_METHODS : []),
            ...(($payments['cash'] ?? false) ? ['cash'] : []),
        ];
    }

    /**
     * @return array<string, mixed>|null null when the service call failed
     */
    private function summary(string $function, int $shopId): ?array
    {
        try {
            return $this->serviceClient->call($function, ['shop_id' => $shopId]);
        } catch (ServiceException $ex) {
            $this->logger->error("home: '{$function}' call failed", [
                'function' => $function,
                'shop_id' => $shopId,
                'status' => $ex->statusCode(),
                'error' => $ex->getMessage(),
            ]);

            return null;
        }
    }
}
