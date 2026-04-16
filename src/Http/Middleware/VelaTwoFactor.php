<?php

namespace VelaBuild\Core\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;

class VelaTwoFactor
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth('vela')->user();

        if ($user && $user->two_factor_code) {
            if (Carbon::createFromFormat(config('vela.date_format') . ' ' . config('vela.time_format'), $user->two_factor_expires_at)->lt(now())) {
                $user->resetTwoFactorCode();
                auth('vela')->logout();

                return redirect()->route('vela.auth.login')->with('message', __('vela::global.two_factor.expired'));
            }

            if (! $request->routeIs('vela.auth.two-factor.*')) {
                return redirect()->route('vela.auth.two-factor.show');
            }
        }

        return $next($request);
    }
}
