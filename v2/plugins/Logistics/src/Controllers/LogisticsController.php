<?php

declare(strict_types=1);

namespace Plugins\Logistics\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class LogisticsController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    public function getLogistics(string $shopId): Response
    {
        try {
            $response = $this->serviceClient->call(
                'shop_logistics_get',
                ['id' => $shopId],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["logistics" => $response]);
    }

    public function updateLogistics(Request $request, string $shopId): Response
    {
        try {
            $response = $this->serviceClient->call(
                'shop_logistics_update',
                ['id' => $shopId],
                $request->body,
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["logistics" => $response]);
    }
}
