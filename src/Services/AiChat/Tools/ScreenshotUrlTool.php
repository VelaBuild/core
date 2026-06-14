<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\BrowserRenderingService;

class ScreenshotUrlTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $url = $parameters['url'] ?? '';
        if (!$url) {
            return ['error' => 'url is required'];
        }

        $renderer = app(BrowserRenderingService::class);
        if (!$renderer->isConfigured()) {
            return ['error' => 'Browser rendering not configured. Set CLOUDFLARE_BROWSER_RENDERING_URL in .env.'];
        }

        $width = $parameters['width'] ?? 1280;
        $height = $parameters['height'] ?? 800;
        $fullPage = $parameters['full_page'] ?? false;

        $base64 = $renderer->screenshot($url, [
            'width' => $width,
            'height' => $height,
            'full_page' => $fullPage,
        ]);

        if (!$base64) {
            return ['error' => 'Screenshot capture failed'];
        }

        $filename = 'screenshot-' . date('Ymd-His') . '.png';
        $storagePath = 'public/ai-screenshots/' . $filename;
        \Illuminate\Support\Facades\Storage::put($storagePath, base64_decode($base64));

        return [
            'success' => true,
            'url' => \Illuminate\Support\Facades\Storage::url($storagePath),
            'filename' => $filename,
            'width' => $width,
            'height' => $height,
        ];
    }
}
