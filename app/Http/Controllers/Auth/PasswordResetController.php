<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Self-service password reset for shop staff who have an email address.
 *
 * Staff sign in with a username, and most floor staff have no work email, so
 * this is a convenience for the people who do — chiefly the shop owner, who
 * otherwise has nobody above them to reset it. Anyone without an address still
 * asks an admin, which is why the request screen says so.
 *
 * Reset links are generated against the current host, so a link always returns
 * to the shop subdomain it was requested from.
 */
class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Always reports the same outcome.
     *
     * Distinguishing "sent" from "no such address" would turn this form into a
     * way to enumerate which addresses hold an account at a given shop, so the
     * status from the broker is deliberately discarded. Genuine failures still
     * reach the log through the mailer.
     */
    public function email(ForgotPasswordRequest $request): RedirectResponse
    {
        Password::broker()->sendResetLink($request->only('email'));

        return back()->with(
            'status',
            'If that address has an account here, a reset link is on its way. It expires in one hour.',
        );
    }

    public function reset(string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) request()->query('email', ''),
        ]);
    }

    public function update(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    // Invalidate "remember me" on every other device: whoever
                    // reset this may be locking someone else out on purpose.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'That reset link is invalid or has expired. Request a new one.']);
        }

        return to_route('login')->with('status', 'Your password has been changed. Sign in with it now.');
    }
}
