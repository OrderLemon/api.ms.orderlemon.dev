<?php

declare(strict_types=1);

namespace Plugins\Payment_methods\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class PaymentMethodsController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    public function list(string $shopId): Response
    {
        try {
            $response = $this->serviceClient->call(
                'payment_methods_list',
                ['shop_id' => $shopId],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["payment_methods" => $response]);
    }

    public function checkout(string $shopId): Response
    {
        try {
            $response = $this->serviceClient->call(
                'payment_methods_checkout',
                ['shop_id' => $shopId],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["payment_methods" => $response]);
    }

    public function configure(Request $request, string $shopId, string $paymentMethodId): Response
    {
        try {
            $response = $this->serviceClient->call(
                'payment_methods_configure',
                ['shop_id' => $shopId, 'payment_method_id' => $paymentMethodId],
                $request->body,
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["payment_method" => $response]);
    }
}
