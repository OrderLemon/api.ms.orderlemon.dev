<?php

declare(strict_types=1);

namespace Plugins\Home\Controllers;

use Plugins\Home\Services\HomeService;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Response;

final class HomeController
{
    public function __construct(
        private readonly HomeService $home,
    ) {}

    public function show(string $shopId): Response
    {
        return Response::ok($this->home->forShop($this->requireShopId($shopId)));
    }

    private function requireShopId(string $value): int
    {
        $shopId = filter_var($value, FILTER_VALIDATE_INT);

        if ($shopId === false || $shopId <= 0) {
            throw new ValidationException(['shop_id' => 'A valid shop_id is required']);
        }

        return $shopId;
    }
}
