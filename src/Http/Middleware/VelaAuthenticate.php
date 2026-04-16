<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VelaAuthenticate
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth('vela')->check()) {
            if ($request->expectsJson()) {
                abort(401);
            }
            return redirect()->guest(route('vela.auth.login'));
        }

        return $next($request);
    }
}
