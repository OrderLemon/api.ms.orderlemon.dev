<?php

declare(strict_types=1);

namespace Plugins\Clients\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Response;

final class ClientsController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    /** GET /clients/{shop_id}/{phonenumber} */
    public function getInfo(string $shopId, string $phonenumber): Response
    {
        $shopId = $this->requireShopId($shopId);
        $phonenumber = trim($phonenumber);

        if ($phonenumber === '') {
            throw new ValidationException(['phonenumber' => 'phonenumber is required']);
        }

        try {
            $response = $this->serviceClient->call(
                'client_get_info',
                ['shop_id' => $shopId, 'phonenumber' => $phonenumber],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok($response);
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
