<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShopUnavailableResponse
{
    public function notFound(Request $request): Response
    {
        return $this->respond($request, Response::HTTP_NOT_FOUND);
    }

    public function unavailable(Request $request): Response
    {
        return $this->respond($request, Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function respond(Request $request, int $status): Response
    {
        $response = $request->expectsJson()
            ? response()->json(['message' => 'Shop unavailable.'], $status)
            : response()->view('errors.shop-unavailable', status: $status);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
