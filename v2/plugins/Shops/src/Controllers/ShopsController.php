<?php

declare(strict_types=1);

namespace Plugins\Shops\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Response;

final class ShopsController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    /** GET /shops/{id} */
    public function getById(string $id): Response
    {
        try {
            $response = $this->serviceClient->call(
                'shop_get_by_id',
                ['id' => $id],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["shop" => $response]);
    }

    /** GET /shops/phone/{phone} */
    public function getByPhone(string $phone): Response
    {
        $phone = trim($phone);

        if ($phone === '') {
            throw new ValidationException(['phone' => 'phone is required']);
        }

        try {
            $response = $this->serviceClient->call(
                'shop_get_by_phone',
                [],
                ['phone' => $phone],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["shop" => $response]);
    }
}
