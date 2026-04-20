<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use VelaBuild\Core\Http\Controllers\Controller;

class ServiceWorkerController extends Controller
{
    public function show()
    {
        $pwaEnabled = vela_config('pwa_enabled', '1');

        if ($pwaEnabled === '0') {
            return response("self.addEventListener('install', () => self.skipWaiting()); self.addEventListener('activate', () => self.registration.unregister());", 200)
                ->header('Content-Type', 'application/javascript')
                ->header('Service-Worker-Allowed', '/');
        }

        $swVersion = vela_config('sw_version', '1');
        $precacheUrls = vela_config('pwa_precache_urls', '');
        $offlineEnabled = vela_config('pwa_offline_enabled', '1');

        $content = view('vela::pwa.sw', [
            'version' => $swVersion,
            'precacheUrls' => $precacheUrls,
            'offlineEnabled' => $offlineEnabled,
        ])->render();

        return response($content, 200)
            ->header('Content-Type', 'application/javascript')
            ->header('Cache-Control', 'no-cache')
            ->header('Service-Worker-Allowed', '/');
    }
}
