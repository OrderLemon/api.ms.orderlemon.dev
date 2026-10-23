<?php

declare(strict_types=1);

namespace Plugins\Company\Controllers;

use Plugins\Payment_methods\Services\PaymentMethodsService;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Cluster\ServiceClient;


final class CompanyController
{
    public function __construct(
        private readonly ServiceClient $client,
        private readonly Logger $logger,
    ) {}

    public function getLegal(string $companyId): Response
    {
        $companyId = $this->assertCompanyId($companyId);
        
        $resposne = $this->call("shop_get_company_legal", ["company_id" => $company_id]);

        return Response::ok(["legal" => $response]);
    }
  
    public function updateLegal(string $companyId): Response
    {
        $companyId = $this->assertCompanyId($companyId);
        
        $payload = $this->assertPayload($request->body);
        
        $resposne = $this->call("shop_update_company_legal", ["company_id" => $company_id], $payload);

        return Response::ok(["legal" => $response]);
    }
  
    private function assertCompanyId(string $companyId) : int
    {
        if(filter_var($companyId, FILTER_VALIDATE_INT)
                || (int)$companyId < 1){
            throw new \Pmsrapi\V2\Exception\ValidationException(["gateway company id" => "Invalid company id provided!"]);
        }
        
        return (int)$companyId;
    }
    
    private function assertPayload(array $body) : array
    {
        if(!is_array($body) || empty($body)){
            throw new \Pmsrapi\V2\Exception\ValidationException(["gateway company payload" => "Missing or empty payload!"]);
        }
        
        return $body;
    }
    
    private function call(string $function, array $params = [], array $payload = []) : array
    {
        try{
            return $this->client->call($function, $params, $payload);
        } catch (\Pmsrapi\V2\Exception\ApiException $ex) {
            $this->logger->error(
                    [
                        "message" => "Proxy call failed!",
                        "location" => "Company plugin",
                        "function" => $function,
                        "error_code" => $ex->errorCode(),
                        "error_message" => $ex->getMessage()
                    ]
            );
            
            return ["message" => "Error while retrieving data"];
        }
    }

}
