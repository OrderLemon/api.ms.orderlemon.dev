<?php

declare(strict_types=1);

namespace Plugins\Campaigns\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Http\Response;

final class CampaignsController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    public function getCampaigns(string $shopId): Response
    {
        try{
            $response = $this->serviceClient->call(
                'get_campaigns',
                ['shop_id' => $shopId],
            );
        }catch(ServiceException $ex){
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        if(isset($response["campaigns"])){
            return Response::ok(["campaigns" => $response["campaigns"]]);
        }

        return Response::ok(["campaigns" => []]);

    }
}
