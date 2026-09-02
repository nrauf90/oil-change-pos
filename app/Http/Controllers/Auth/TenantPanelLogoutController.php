<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Tenancy\LogoutTenantSession;
use App\Http\Controllers\Controller;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Illuminate\Http\Request;

final class TenantPanelLogoutController extends Controller
{
    public function __construct(private readonly LogoutTenantSession $logout) {}

    public function __invoke(Request $request): LogoutResponse
    {
        $this->logout->handle($request);

        return app(LogoutResponse::class);
    }
}
