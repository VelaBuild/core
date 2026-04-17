<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use VelaBuild\Core\Services\McpSettingsService;

class VelaMcpAuth
{
    public function __construct(private McpSettingsService $mcp)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        // When disabled, return a standard 404 — don't reveal MCP exists
        if (!$this->mcp->isEnabled()) {
            abort(404);
        }

        // Throttle auth attempts by IP to prevent brute-force
        $throttleKey = 'mcp-auth:' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 30)) {
            return response()->json(['error' => 'Too many requests'], 429);
        }

        $token = $request->bearerToken();

        if (!$token || !$this->mcp->validateToken($token)) {
            RateLimiter::hit($throttleKey, 60);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Successful auth — clear the limiter for this IP
        RateLimiter::clear($throttleKey);

        return $next($request);
    }
}
