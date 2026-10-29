<?php

declare(strict_types=1);

namespace Plugins\Shops\Controllers;

use Plugins\Auth\Access\EndpointSecurity;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class ShopsController
{
    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly EndpointSecurity $security,
    ) {}

    public function listByCompany(Request $request, string $companyId): Response
    {
        $this->security->approveCompany($request, $companyId);

        try {
            $response = $this->serviceClient->call(
                'shop_list_by_company',
                ['company_id' => $companyId],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["shops" => $response]);
    }

    public function getShop(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $shopId, "settings");

        try {
            $response = $this->serviceClient->call(
                'shop_get',
                ['id' => $shopId],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["shop" => $response]);
    }

    public function updateGeneral(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $shopId, "settings");

        try {
            $response = $this->serviceClient->call(
                'shop_update_general',
                ['id' => $shopId],
                $request->body,
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["shop" => $response]);
    }

    public function getSettings(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $shopId, "settings");

        try {
            $response = $this->serviceClient->call(
                'shop_settings_get',
                ['id' => $shopId],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["settings" => $response]);
    }

    public function updateSettings(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $shopId, "settings");

        try {
            $response = $this->serviceClient->call(
                'shop_settings_update',
                ['id' => $shopId],
                $request->body,
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        // shop.ms answers 200 with success:false when nothing changed.
        return Response::ok(["updated" => ($response['success'] ?? true) !== false]);
    }

    public function getCalendar(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $shopId, "calendar");

        try {
            $response = $this->serviceClient->call(
                'shop_calendar_get',
                ['id' => $shopId],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["calendar" => $response]);
    }

    public function updateCalendar(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $shopId, "calendar");

        try {
            $response = $this->serviceClient->call(
                'shop_calendar_update',
                ['id' => $shopId],
                $request->body,
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["updated" => (bool) ($response['updated'] ?? false)]);
    }

    public function getQr(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $shopId, "settings");

        try {
            $response = $this->serviceClient->call(
                'shop_qr_get',
                ['id' => $shopId],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["qr" => $response]);
    }

    public function createQr(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $shopId, "settings");

        try {
            $response = $this->serviceClient->call(
                'shop_qr_create',
                ['id' => $shopId],
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["qr" => $response]);
    }

    public function updateQr(Request $request, string $shopId): Response
    {
        $this->security->approveCall($request, $shopId, "settings");

        try {
            $response = $this->serviceClient->call(
                'shop_qr_update',
                ['id' => $shopId],
                $request->body,
            );
        } catch (ServiceException $ex) {
            return Response::error($ex->statusCode(), ["error" => $ex->getMessage()]);
        }

        return Response::ok(["qr" => $response]);
    }
}
