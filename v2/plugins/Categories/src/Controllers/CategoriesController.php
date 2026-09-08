<?php

declare(strict_types=1);

namespace Plugins\Categories\Controllers;

use Exception;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Exception\ServiceException;

final class CategoriesController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    public function getCategories(string $shopId): Response
    {
        try{
            $response = $this->serviceClient->call(
                'get_categories',
                ['shop_id' => $shopId],
            );
        }catch(ServiceException $ex){
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        if(isset($response["rows"])){
            return Response::ok(["categories" => $response["rows"]]);
        }

        return Response::ok(["categories" => []]);

    }
}
