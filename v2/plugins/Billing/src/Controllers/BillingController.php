<?php

declare(strict_types=1);

namespace Plugins\Billing\Controllers;

use Plugins\Payment_methods\Services\PaymentMethodsService;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Cluster\ServiceClient;


final class BillingController
{
    public function __construct(
        private readonly ServiceClient $client,
        private readonly Logger $logger,
    ) {}

    public function plan(): Response
    {
        $this->assertShopId();
        
        $resposne = $this->call("payway_get_plan", ["shop_id" => shop_id]);

        return Response::ok(["plan" => $response]);
    }
    
    public function invoices(): Response
    {
        $this->assertShopId();
        
        $resposne = $this->call("payway_get_invoices", ["shop_id" => shop_id]);

        return Response::ok(["invoices" => $response]);
    }
    
    public function invoice(string $invoiceId): Response
    {
        $this->assertShopId();
        
        $resposne = $this->call("payway_get_invoice", ["shop_id" => shop_id, "invoice_id" => $invoiceId]);

        return Response::ok(["invoice" => $response]);
    }
    
    public function invoicePdf(string $invoiceId): Response
    {
        $this->assertShopId();
        
        $resposne = $this->call("payway_get_invoice_pdf", ["shop_id" => shop_id, "invoice_id" => $invoiceId]);

        return Response::ok(["pdf" => $response]);
    }
    
    public function paymentMethod(): Response
    {
        $this->assertShopId();
        
        $resposne = $this->call("payway_get_payout_payment_method", ["shop_id" => shop_id]);

        return Response::ok(["payment_method" => $response]);
    }
    
    private function assertShopId() : void
    {
        if(!defined("shop_id") || filter_var(shop_id, FILTER_VALIDATE_INT)
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
                    [
                        "message" => "Proxy call failed!",
                        "function" => $function,
                        "location" => "Billing",
                        "error_code" => $ex->errorCode(),
                        "error_message" => $ex->getMessage()
                    ]
            );
            
            return ["message" => "Error while retrieving data"];
        }
    }

}
