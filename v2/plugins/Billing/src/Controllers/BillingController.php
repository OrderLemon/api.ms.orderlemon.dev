<?php

declare(strict_types=1);

namespace Plugins\Billing\Controllers;

use Plugins\Auth\Access\EndpointSecurity;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Cluster\ServiceClient;


final class BillingController
{
    public function __construct(
        private readonly ServiceClient $client,
        private readonly Logger $logger,
        private readonly EndpointSecurity $security,
    ) {}

    public function plan(Request $request): Response
    {
        $this->assertShopId();
        $this->security->approveCall($request, shop_id, "billing");
        
        $response = $this->call("payway_get_plan", ["shop_id" => shop_id]);

        return Response::ok(["plan" => $response]);
    }
    
    public function invoices(Request $request): Response
    {
        $this->assertShopId();
        $this->security->approveCall($request, shop_id, "accounting");
        
        $response = $this->call("payway_get_invoices", ["shop_id" => shop_id]);

        return Response::ok(["invoices" => $response]);
    }
    
    public function invoice(Request $request, string $invoiceId): Response
    {
        $this->assertShopId();
        $this->security->approveCall($request, shop_id, "accounting");
        
        $response = $this->call("payway_get_invoice", ["shop_id" => shop_id, "invoice_id" => $invoiceId]);

        return Response::ok(["invoice" => $response]);
    }
    
    public function invoicePdf(Request $request, string $invoiceId): Response
    {
        $this->assertShopId();
        $this->security->approveCall($request, shop_id, "accounting");
        
        $response = $this->call("payway_get_invoice_pdf", ["shop_id" => shop_id, "invoice_id" => $invoiceId]);

        return Response::ok(["pdf" => $response]);
    }
    
    public function paymentMethod(Request $request): Response
    {
        $this->assertShopId();
        $this->security->approveCall($request, shop_id, "billing");
        
        $response = $this->call("payway_get_payout_payment_method", ["shop_id" => shop_id]);

        return Response::ok(["payment_method" => $response]);
    }
    
    private function assertShopId() : void
    {
        if(!defined("shop_id") || !filter_var(shop_id, FILTER_VALIDATE_INT)
                || shop_id < 1){
            throw new \Pmsrapi\V2\Exception\ValidationException(["gatway shop id" => "Invalid shop id provided!"]);
        }
    }
    
    private function call(string $function, array $params = [], array $payload = []) : array
    {
        try{
            return $this->client->call($function, $params, $payload);
        } catch (\Pmsrapi\V2\Exception\ApiException $ex) {
            $this->logger->error(
                    "Proxy call failed!",
                    [
                        "function" => $function,
                        "location" => "Billing",
                        "error_code" => $ex->errorCode(),
                        "error_message" => $ex->getMessage()
                    ]
            );
            
            throw match ($ex->statusCode()) {
                404 => new \Pmsrapi\V2\Exception\ApiException('That recource could not be found.', 404),
                422 => new \Pmsrapi\V2\Exception\ValidationException([], 'The information you provided could not be processed.'),
                429 => new \Pmsrapi\V2\Exception\ServiceException(
                    'Too many requests — please wait a moment and try again.',
                    429,
                    'rate_limited',
                ),
                default => new \Pmsrapi\V2\Exception\ServiceException(
                    'Something went wrong processing this request. Please try again.',
                    $ex->statusCode() >= 500 ? 502 : $ex->statusCode(),
                ),
            };
        }
    }

}
