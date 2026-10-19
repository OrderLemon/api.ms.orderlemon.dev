<?php

declare(strict_types=1);

namespace Plugins\Dashboard\Controllers;

use Plugins\Dashboard\Services\DashboardService;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class DashboardController
{
    private const array ALLOWED_INTERVALS = [1, 7, 30];

    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    public function show(Request $request, string $shopId): Response
    {
        return Response::ok($this->dashboard->forShop(
            $this->requireShopId($shopId),
            $this->requireInterval($request->query('interval')),
        ));
    }

    private function requireShopId(string $value): int
    {
        $shopId = filter_var($value, FILTER_VALIDATE_INT);

        if ($shopId === false || $shopId <= 0) {
            throw new ValidationException(['shop_id' => 'A valid shop_id is required']);
        }

        return $shopId;
    }

    private function requireInterval(?string $value): int
    {
        $interval = filter_var($value, FILTER_VALIDATE_INT);

        if ($interval === false || !in_array($interval, self::ALLOWED_INTERVALS, true)) {
            throw new ValidationException(['interval' => 'interval must be one of: 1, 7, 30']);
        }

        return $interval;
    }
}
