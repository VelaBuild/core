<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use VelaBuild\Core\Services\PermissionGates;

class VelaAuthGates
{
    public function handle($request, Closure $next)
    {
        $velaUser = auth('vela')->user();

        if (!$velaUser) {
            return $next($request);
        }

        // Set the default guard to 'vela' so Gate checks resolve the correct user
        Auth::shouldUse('vela');

        app(PermissionGates::class)->register();

        return $next($request);
    }
}
