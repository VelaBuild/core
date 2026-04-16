<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VelaSetTemplate
{
    public function handle(Request $request, Closure $next)
    {
        // Template is set at boot from storage/app/vela-site.php
        // This middleware remains as a hook point for future extensions
        return $next($request);
    }
}
