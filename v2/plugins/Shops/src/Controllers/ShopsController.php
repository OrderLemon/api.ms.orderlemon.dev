<?php

declare(strict_types=1);

namespace Plugins\Shops\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class ShopsController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    public function getShop(string $shopId): Response
    {
        try {
            $response = $this->serviceClient->call(
                'shop_get',
                ['shop_id' => $shopId],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["shop" => $response]);
    }

    public function updateGeneral(Request $request, string $shopId): Response
    {
        try {
            $response = $this->serviceClient->call(
                'shop_update_general',
                ['shop_id' => $shopId],
                $request->body,
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["shop" => $response]);
    }
}
