<?php

declare(strict_types=1);

namespace Plugins\Ticket\Controllers;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Response;

final class TicketController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
    ) {}

    /** GET /ticket/{shop_id}/{order_id} */
    public function getData(string $shopId, string $orderId): Response
    {
        $shopId = $this->requirePositiveInt($shopId, 'shop_id');
        $orderId = $this->requirePositiveInt($orderId, 'order_id');

        try {
            $response = $this->serviceClient->call(
                'cart_ticket_data',
                [],
                ['shop_id' => $shopId, "data" => ['order_id' => $orderId]],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok($response);
    }

    private function requirePositiveInt(string $value, string $field): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);

        if ($int === false || $int <= 0) {
            throw new ValidationException([$field => "A valid {$field} is required"]);
        }

        return $int;
    }
}
