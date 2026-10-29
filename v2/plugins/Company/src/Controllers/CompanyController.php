<?php

declare(strict_types=1);

namespace Plugins\Company\Controllers;

use Plugins\Auth\Access\EndpointSecurity;
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
        private readonly EndpointSecurity $security,
    ) {}

    public function getLegal(Request $request, string $companyId): Response
    {
        $this->security->approveCompany($request, $companyId);

        $companyId = $this->assertCompanyId($companyId);
        
        $response = $this->call("shop_get_company_legal", ["company_id" => $companyId]);

        return Response::ok(["legal" => $response]);
    }
  
    public function updateLegal(Request $request, string $companyId): Response
    {
        $this->security->approveCompany($request, $companyId);

        $companyId = $this->assertCompanyId($companyId);
        
        $payload = $this->assertPayload($request->body);
        
        $response = $this->call("shop_update_company_legal", ["company_id" => $companyId], $payload);

        return Response::ok(["legal" => $response]);
    }
  
    private function assertCompanyId(string $companyId) : int
    {
        if(!filter_var($companyId, FILTER_VALIDATE_INT)
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
                    "Proxy call failed!",
                    [
                        "location" => "Company plugin",
                        "function" => $function,
                        "error_code" => $ex->errorCode(),
                        "error_message" => $ex->getMessage()
                    ]
            );
            throw match ($ex->statusCode()) {
                404 => new \Pmsrapi\V2\Exception\ApiException('That company could not be found.', 404),
                422 => new \Pmsrapi\V2\Exception\ValidationException([], 'The information you provided could not be processed.'),
                429 => new \Pmsrapi\V2\Exception\ServiceException(
                    'Too many requests — please wait a moment and try again.',
                    429,
                    'rate_limited',
                ),
                default => new ServiceException(
                    'Something went wrong processing this request. Please try again.',
                    $ex->statusCode() >= 500 ? 502 : $ex->statusCode(),
                ),
            };
        }
    }

}
