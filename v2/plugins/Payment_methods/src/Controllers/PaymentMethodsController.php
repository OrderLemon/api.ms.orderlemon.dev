<?php

declare(strict_types=1);

namespace Plugins\Payment_methods\Controllers;

use Plugins\Auth\Access\EndpointSecurity;
use Plugins\Payment_methods\Services\PaymentMethodsService;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class PaymentMethodsController
{
    public function __construct(
        private readonly PaymentMethodsService $service,
        private readonly EndpointSecurity $security,
    ) {}

    public function list(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $shopId, "payments");

        $response = $this->service->call('payment_methods_list', ['shop_id' => $shopId]);

        return Response::ok(["payment_methods" => $response]);
    }

    public function checkout(string $shopId): Response
    {
        $response = $this->service->call('payment_methods_checkout', ['shop_id' => $shopId]);

        return Response::ok(["payment_methods" => $response]);
    }

    public function configure(Request $request, string $shopId, string $paymentMethodId): Response
    {
        $this->security->approveCall($request, $shopId, "payments");

        $response = $this->service->call(
            'payment_methods_configure',
            ['shop_id' => $shopId, 'payment_method_id' => $paymentMethodId],
            $request->body,
        );

        return Response::ok(["payment_method" => $response]);
    }
}
