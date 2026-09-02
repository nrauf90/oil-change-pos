<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\InitializeTenancy;
use App\Http\Requests\LoginRequest;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Kill the pre-login session id so a fixated cookie cannot be reused.
        $request->session()->regenerate();
        $request->session()->put(
            InitializeTenancy::SESSION_SHOP_KEY,
            resolve(TenantContext::class)->id(),
        );

        $request->user()->forceFill(['last_login_at' => now()])->save();

        // 'home' dispatches on what this member of staff may actually do —
        // sending a technician to the counter screen would 403 them out of the
        // app the moment they signed in.
        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->forget(InitializeTenancy::SESSION_SHOP_KEY);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        return to_route('login');
    }
}
