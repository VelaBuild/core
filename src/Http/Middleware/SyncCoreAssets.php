<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use VelaBuild\Core\Services\AssetSync;

/**
 * After a package update, the first page anyone opens brings the site's
 * public files up to date — see AssetSync for why nobody else would.
 */
class SyncCoreAssets
{
    public function __construct(private AssetSync $sync)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (config('vela.assets.auto_sync', true) && !app()->runningUnitTests() && $request->isMethod('GET')) {
            $this->sync->syncIfStale();
        }

        return $next($request);
    }
}
