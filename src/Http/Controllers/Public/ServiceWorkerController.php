<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\VelaConfig;

class ServiceWorkerController extends Controller
{
    public function show()
    {
        $pwaEnabled = VelaConfig::where('key', 'pwa_enabled')->value('value');

        if ($pwaEnabled === '0') {
            return response("self.addEventListener('install', () => self.skipWaiting()); self.addEventListener('activate', () => self.registration.unregister());", 200)
                ->header('Content-Type', 'application/javascript')
                ->header('Service-Worker-Allowed', '/');
        }

        $swVersion = VelaConfig::where('key', 'sw_version')->value('value') ?? '1';
        $precacheUrls = VelaConfig::where('key', 'pwa_precache_urls')->value('value') ?? '';
        $offlineEnabled = VelaConfig::where('key', 'pwa_offline_enabled')->value('value') ?? '1';

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
