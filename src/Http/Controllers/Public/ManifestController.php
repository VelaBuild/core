<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use Illuminate\Http\Request;
use VelaBuild\Core\Http\Controllers\Controller;

class ManifestController extends Controller
{
    public function show(Request $request, ?string $locale = null)
    {
        $pwaEnabled = vela_config('pwa_enabled', '1');
        if ($pwaEnabled === '0') {
            abort(404);
        }

        $locale = preg_replace('/[^a-zA-Z0-9_-]/', '', $locale ?? app()->getLocale());
        $cachePath = storage_path("app/pwa/manifest-{$locale}.json");

        // Return cached manifest if fresh (< 24 hours)
        if (file_exists($cachePath) && (time() - filemtime($cachePath)) < 86400) {
            return response(file_get_contents($cachePath), 200)
                ->header('Content-Type', 'application/manifest+json')
                ->header('Cache-Control', 'public, max-age=86400');
        }

        // Build manifest
        $name = vela_config('pwa_name') ?: config('app.name');
        $shortName = vela_config('pwa_short_name') ?: substr($name, 0, 12);
        $description = vela_config('pwa_description', '');
        $display = vela_config('pwa_display', 'standalone');
        $themeColor = vela_config('pwa_theme_color', '#1f2937');
        $bgColor = vela_config('pwa_background_color', '#ffffff');

        $defaultLocale = config('app.locale', 'en');
        $startUrl = ($locale === $defaultLocale) ? '/' : '/' . $locale;

        $manifest = [
            'name' => $name,
            'short_name' => $shortName,
            'description' => $description,
            'start_url' => $startUrl,
            'display' => $display,
            'orientation' => 'any',
            'theme_color' => $themeColor,
            'background_color' => $bgColor,
            'lang' => $locale,
            'dir' => 'ltr',
            'icons' => [],
        ];

        // Only include icons that actually exist on disk
        $iconDir = storage_path('app/public/pwa-icons');
        $standardSizes = [48, 72, 96, 128, 144, 192, 512];
        foreach ($standardSizes as $size) {
            if (file_exists("{$iconDir}/icon-{$size}x{$size}.png")) {
                $manifest['icons'][] = [
                    'src' => "/storage/pwa-icons/icon-{$size}x{$size}.png",
                    'sizes' => "{$size}x{$size}",
                    'type' => 'image/png',
                ];
            }
        }
        foreach ([192, 512] as $size) {
            if (file_exists("{$iconDir}/icon-{$size}x{$size}-maskable.png")) {
                $manifest['icons'][] = [
                    'src' => "/storage/pwa-icons/icon-{$size}x{$size}-maskable.png",
                    'sizes' => "{$size}x{$size}",
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ];
            }
        }

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Cache the manifest
        $cacheDir = storage_path('app/pwa');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cachePath, $json);

        return response($json, 200)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
