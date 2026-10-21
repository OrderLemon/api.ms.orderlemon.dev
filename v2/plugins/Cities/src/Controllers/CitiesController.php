<?php

declare(strict_types=1);

namespace Plugins\Cities\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

final class CitiesController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
    ) {}

    /** GET /cities/{shop_id}?country= — every city of the country the shop's company is registered in. */
    public function getCities(Request $request, string $shopId): Response
    {
        $params = ['id' => $shopId];
        $country = $request->query('country');
        if ($country !== null) {
            $params['country'] = $country;
        }

        try {
            $response = $this->serviceClient->call('shop_logistics_cities', $params);
        } catch (ServiceException $ex) {
            $this->logger->error('Proxied call failed', [
                'function' => 'shop_logistics_cities',
                'shop_id' => $shopId,
                'status' => $ex->statusCode(),
                'message' => $ex->getMessage(),
            ]);

            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok($response);
    }
}
