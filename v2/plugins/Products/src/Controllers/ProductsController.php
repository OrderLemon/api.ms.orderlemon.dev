<?php

declare(strict_types=1);

namespace Plugins\Products\Controllers;

use Exception;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Exception\ServiceException;

final class ProductsController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    public function getProducts(string $shopId): Response
    {
        try{
            $response = $this->serviceClient->call(
                'get_products',
                ['shop_id' => $shopId],
            );
        }catch(ServiceException $ex){
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        if(isset($response["products"])){
            return Response::ok(["products" => $response["products"]]);
        }

        return Response::ok(["products" => []]);

    }
}