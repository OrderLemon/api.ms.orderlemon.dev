<?php

declare(strict_types=1);

namespace Plugins\Dashboard\Controllers;

use DateTimeImmutable;
use Plugins\Dashboard\Services\DashboardService;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class DashboardController
{
    private const int MAX_RANGE_DAYS = 366;

    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    public function show(Request $request, string $shopId): Response
    {
        $from = $this->requireDate($request, 'from');
        $to = $this->requireDate($request, 'to');

        if ($from > $to) {
            throw new ValidationException(['from' => 'from must be on or before to']);
        }

        if ($from->diff($to)->days + 1 > self::MAX_RANGE_DAYS) {
            throw new ValidationException(['to' => 'The period can be at most ' . self::MAX_RANGE_DAYS . ' days']);
        }

        return Response::ok($this->dashboard->forShop(
            $this->requireShopId($shopId),
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
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

    private function requireDate(Request $request, string $field): DateTimeImmutable
    {
        $value = $request->query($field);
        $date = $value !== null ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ValidationException([$field => "{$field} must be a date as Y-m-d"]);
        }

        return $date;
    }
}
