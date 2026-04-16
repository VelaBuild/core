<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VelaSiteVisibility
{
    public function handle(Request $request, Closure $next)
    {
        // Only act when visibility mode is restricted with holding page enabled
        if (config('vela.visibility.mode') !== 'restricted'
            || !config('vela.visibility.holding_page')) {
            return $next($request);
        }

        $holdingPageId = config('vela.visibility.holding_page_id');
        if (!$holdingPageId) {
            return $next($request);
        }

        // Let authenticated admin users through
        if (auth('vela')->check()) {
            return $next($request);
        }

        // Resolve the holding page slug so we don't redirect it to itself
        $holdingPage = \VelaBuild\Core\Models\Page::find($holdingPageId);
        if (!$holdingPage) {
            return $next($request);
        }

        // Allow the holding page itself, plus essential assets
        $currentPath = trim($request->path(), '/');
        $holdingSlug = $holdingPage->slug;

        // Strip locale prefix for comparison
        $localePrefix = app()->getLocale();
        $pathWithoutLocale = preg_replace('#^' . preg_quote($localePrefix, '#') . '(/|$)#', '', $currentPath);
        $pathWithoutLocale = trim($pathWithoutLocale, '/');

        if ($pathWithoutLocale === $holdingSlug || $currentPath === $holdingSlug) {
            return $next($request);
        }

        // Privacy page must always be accessible (GDPR requirement)
        $privacySlug = ltrim(config('vela.gdpr.privacy_url', '/privacy'), '/');
        if ($pathWithoutLocale === $privacySlug || $currentPath === $privacySlug) {
            return $next($request);
        }

        // Allow static assets, admin, auth, storage, API paths through
        $allowedPrefixes = ['admin', 'vela', 'login', 'storage', 'vendor', 'api', 'manifest.json', 'sw.js', 'offline', 'imgp', 'imgr'];
        $firstSegment = explode('/', $currentPath)[0] ?? '';
        if (in_array($firstSegment, $allowedPrefixes, true)) {
            return $next($request);
        }

        // Redirect to the holding page
        $holdingUrl = url($holdingSlug);
        return redirect($holdingUrl, 302);
    }
}
