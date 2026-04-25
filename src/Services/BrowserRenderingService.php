<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrowserRenderingService
{
    public function isConfigured(): bool
    {
        return !empty(config('vela.browser_rendering.url'));
    }

    public function screenshot(string $url, array $options = []): ?string
    {
        $endpoint = rtrim(config('vela.browser_rendering.url'), '/') . '/screenshot';

        $payload = array_merge([
            'url' => $url,
            'viewport' => ['width' => $options['width'] ?? 1280, 'height' => $options['height'] ?? 800],
            'format' => $options['format'] ?? 'png',
            'fullPage' => $options['full_page'] ?? false,
        ], $options['extra'] ?? []);

        try {
            $response = Http::timeout($options['timeout'] ?? 30)
                ->post($endpoint, $payload);

            if (!$response->successful()) {
                Log::error('Browser rendering screenshot failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
                return null;
            }

            return base64_encode($response->body());
        } catch (\Throwable $e) {
            Log::error('Browser rendering screenshot error', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function pdf(string $url, array $options = []): ?string
    {
        $endpoint = rtrim(config('vela.browser_rendering.url'), '/') . '/pdf';

        $payload = array_merge([
            'url' => $url,
            'format' => 'A4',
            'printBackground' => true,
        ], $options['extra'] ?? []);

        try {
            $response = Http::timeout($options['timeout'] ?? 30)
                ->post($endpoint, $payload);

            if (!$response->successful()) {
                Log::error('Browser rendering PDF failed', ['url' => $url, 'status' => $response->status()]);
                return null;
            }

            return base64_encode($response->body());
        } catch (\Throwable $e) {
            Log::error('Browser rendering PDF error', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function html(string $url, array $options = []): ?string
    {
        $endpoint = rtrim(config('vela.browser_rendering.url'), '/') . '/content';

        try {
            $response = Http::timeout($options['timeout'] ?? 30)
                ->post($endpoint, ['url' => $url]);

            if (!$response->successful()) {
                Log::error('Browser rendering content failed', ['url' => $url, 'status' => $response->status()]);
                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::error('Browser rendering content error', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
