<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\BrowserRenderingService;

class BrowseUrlTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        // A path on its own means a page on this site, so the model cannot
        // reach for a placeholder domain when asked to look at its own work.
        $url = FetchUrlTool::resolveAgainstThisSite((string) ($parameters['url'] ?? ''));
        $action = $parameters['action'] ?? 'extract';

        if (!$url) {
            return ['error' => 'url is required'];
        }

        $renderer = app(BrowserRenderingService::class);
        if (!$renderer->isConfigured()) {
            if ($action === 'extract') {
                return $this->fallbackExtract($url);
            }
            return ['error' => 'Browser rendering not configured. Set CLOUDFLARE_BROWSER_RENDERING_URL. For basic extraction, use action: "extract" which falls back to HTTP fetch.'];
        }

        return match ($action) {
            'extract' => $this->extract($renderer, $url),
            'screenshot' => $this->screenshot($renderer, $url, $parameters),
            'html' => $this->getHtml($renderer, $url),
            'evaluate' => $this->evaluate($renderer, $url, $parameters),
            'pdf' => $this->getPdf($renderer, $url),
            default => ['error' => "Unknown action: {$action}. Available: extract, screenshot, html, evaluate, pdf"],
        };
    }

    private function extract(BrowserRenderingService $renderer, string $url): array
    {
        $data = $renderer->extractStructured($url);
        if (!$data) {
            return $this->fallbackExtract($url);
        }
        return ['success' => true, 'url' => $url, 'method' => 'browser'] + $data;
    }

    private function screenshot(BrowserRenderingService $renderer, string $url, array $params): array
    {
        $base64 = $renderer->screenshot($url, [
            'width' => $params['width'] ?? 1280,
            'height' => $params['height'] ?? 800,
            'full_page' => $params['full_page'] ?? false,
        ]);

        if (!$base64) {
            return ['error' => 'Screenshot failed'];
        }

        $filename = 'browse-' . md5($url) . '-' . time() . '.png';
        $path = 'public/ai-screenshots/' . $filename;
        \Illuminate\Support\Facades\Storage::put($path, base64_decode($base64));

        return [
            'success' => true,
            'url' => $url,
            'screenshot_url' => \Illuminate\Support\Facades\Storage::url($path),
        ];
    }

    private function getHtml(BrowserRenderingService $renderer, string $url): array
    {
        $html = $renderer->html($url);
        if (!$html) {
            return ['error' => 'Failed to get rendered HTML'];
        }
        return [
            'success' => true,
            'url' => $url,
            'html' => mb_substr($html, 0, 200_000),
            'truncated' => strlen($html) > 200_000,
        ];
    }

    private function evaluate(BrowserRenderingService $renderer, string $url, array $params): array
    {
        $script = $params['script'] ?? '';
        if (!$script) {
            return ['error' => 'script is required for evaluate action'];
        }

        $result = $renderer->evaluate($url, $script);
        if ($result === null) {
            return ['error' => 'Script evaluation failed'];
        }

        return ['success' => true, 'url' => $url, 'result' => $result];
    }

    private function getPdf(BrowserRenderingService $renderer, string $url): array
    {
        $base64 = $renderer->pdf($url);
        if (!$base64) {
            return ['error' => 'PDF generation failed'];
        }

        $filename = 'page-' . md5($url) . '-' . time() . '.pdf';
        $path = 'public/ai-downloads/' . $filename;
        \Illuminate\Support\Facades\Storage::put($path, base64_decode($base64));

        return [
            'success' => true,
            'url' => $url,
            'pdf_url' => \Illuminate\Support\Facades\Storage::url($path),
        ];
    }

    private function fallbackExtract(string $url): array
    {
        $tool = app(FetchPageResourcesTool::class);
        $result = $tool->execute(['url' => $url, 'resource' => 'all']);
        $result['method'] = 'http_fetch';
        return $result;
    }
}
