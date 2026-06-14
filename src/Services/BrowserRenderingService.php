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

    public function evaluate(string $url, string $script, array $options = []): ?array
    {
        $endpoint = rtrim(config('vela.browser_rendering.url'), '/') . '/evaluate';

        try {
            $response = Http::timeout($options['timeout'] ?? 30)
                ->post($endpoint, [
                    'url' => $url,
                    'script' => $script,
                    'viewport' => ['width' => $options['width'] ?? 1280, 'height' => $options['height'] ?? 800],
                    'waitUntil' => $options['wait_until'] ?? 'networkidle0',
                ]);

            if (!$response->successful()) {
                Log::error('Browser rendering evaluate failed', ['url' => $url, 'status' => $response->status()]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('Browser rendering evaluate error', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function extractStructured(string $url, array $options = []): ?array
    {
        $script = <<<'JS'
(() => {
    const result = {};
    result.title = document.title;
    result.meta = {};
    document.querySelectorAll('meta[name],meta[property]').forEach(m => {
        result.meta[m.getAttribute('name') || m.getAttribute('property')] = m.getAttribute('content');
    });
    result.headings = [];
    document.querySelectorAll('h1,h2,h3').forEach(h => {
        result.headings.push({ tag: h.tagName, text: h.textContent.trim() });
    });
    result.links = [];
    document.querySelectorAll('a[href]').forEach(a => {
        if (a.href && !a.href.startsWith('javascript:')) result.links.push({ text: a.textContent.trim().substring(0, 100), href: a.href });
    });
    result.images = [];
    document.querySelectorAll('img[src]').forEach(img => {
        result.images.push({ src: img.src, alt: img.alt, width: img.naturalWidth, height: img.naturalHeight });
    });
    result.colors = [];
    const seen = new Set();
    document.querySelectorAll('*').forEach(el => {
        const s = getComputedStyle(el);
        [s.color, s.backgroundColor].forEach(c => { if (c && c !== 'rgba(0, 0, 0, 0)' && !seen.has(c)) { seen.add(c); result.colors.push(c); } });
    });
    result.colors = result.colors.slice(0, 20);
    result.fonts = [];
    const fontsSeen = new Set();
    document.querySelectorAll('*').forEach(el => {
        const f = getComputedStyle(el).fontFamily.split(',')[0].trim().replace(/['"]/g, '');
        if (f && !fontsSeen.has(f)) { fontsSeen.add(f); result.fonts.push(f); }
    });
    result.fonts = result.fonts.slice(0, 10);
    result.viewport = { width: window.innerWidth, height: document.documentElement.scrollHeight };
    return result;
})()
JS;

        return $this->evaluate($url, $script, $options);
    }
}
