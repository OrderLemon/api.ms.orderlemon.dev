<?php

declare(strict_types=1);

namespace Plugins\Products\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Http\Response;

final class ProductsController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    public function getProducts(string $shopId): Response
    {
        return Response::ok($this->serviceClient->call(
            'get_products',
            ['shop_id' => $shopId],
        ));
    }
}