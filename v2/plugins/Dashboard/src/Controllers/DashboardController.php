<?php

declare(strict_types=1);

namespace Plugins\Dashboard\Controllers;

use DateTimeImmutable;
use Plugins\Dashboard\Services\DashboardService;
use Plugins\Auth\Access\EndpointSecurity;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class DashboardController
{
    private const int MAX_RANGE_DAYS = 366;

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly EndpointSecurity $security,
    ) {}

    public function show(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $this->requireId($shopId, 'shop_id'), "analytics");
        
        [$from, $to] = $this->requirePeriod($request);

        return Response::ok($this->dashboard->forShop(
            $this->requireId($shopId, 'shop_id'),
            $from,
            $to,
        ));
    }

    public function showProduct(Request $request, string $shopId, string $productId): Response
    {
        $this->security->approveCall($request, $this->requireId($shopId, 'shop_id'), "products");
        
        [$from, $to] = $this->requirePeriod($request);

        return Response::ok($this->dashboard->forProduct(
            $this->requireId($shopId, 'shop_id'),
            $this->requireId($productId, 'product_id'),
            $from,
            $to,
        ));
    }

    private function requirePeriod(Request $request): array
    {
        $from = $this->requireDate($request, 'from');
        $to = $this->requireDate($request, 'to');

        if ($from > $to) {
            throw new ValidationException(['from' => 'from must be on or before to']);
        }

        if ($from->diff($to)->days + 1 > self::MAX_RANGE_DAYS) {
            throw new ValidationException(['to' => 'The period can be at most ' . self::MAX_RANGE_DAYS . ' days']);
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    private function requireId(string $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false || $id <= 0) {
            throw new ValidationException([$field => "A valid {$field} is required"]);
        }

        return $id;
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
