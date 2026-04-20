<?php

namespace VelaBuild\Core\Http\Controllers\Auth;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Notifications\TwoFactorCodeNotification;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('vela::auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $credentials = $request->only('email', 'password');
        $remember    = $request->boolean('remember');

        if (!auth('vela')->attempt($credentials, $remember)) {
            return back()->withErrors([
                'email' => __('auth.failed'),
            ])->withInput($request->only('email', 'remember'));
        }

        $user = auth('vela')->user();

        // Capture audit fields on every successful login. Admins see these
        // read-only on the edit page; they are never writeable from the UI.
        $user->forceFill([
            'last_login_at' => now(),
            'last_ip'       => $request->ip(),
            'useragent'     => substr((string) $request->userAgent(), 0, 255),
        ])->saveQuietly();

        if ($user->two_factor) {
            $user->generateTwoFactorCode();
            $user->notify(new TwoFactorCodeNotification());
        }

        $request->session()->regenerate();

        \VelaBuild\Core\Jobs\ImportContentFromConfigJob::dispatch();

        return redirect()->intended(route('vela.admin.home'));
    }

    public function logout(Request $request)
    {
        auth('vela')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('vela.auth.login');
    }
}
