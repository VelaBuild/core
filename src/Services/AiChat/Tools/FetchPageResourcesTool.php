<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Models\AiActionLog;

class FetchPageResourcesTool extends BaseTool
{
    private const TIMEOUT = 15;
    private const MAX_BYTES = 256 * 1024;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $url = $parameters['url'] ?? '';
        $resource = $parameters['resource'] ?? 'all';

        if (!$url) {
            return ['error' => 'url is required'];
        }

        $parts = parse_url($url);
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'])) {
            return ['error' => 'Only http(s) URLs allowed'];
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withUserAgent('VelaBuild-AI-Helper/1.0')
                ->get($url);
        } catch (\Throwable $e) {
            return ['error' => 'Fetch failed: ' . $e->getMessage()];
        }

        if (!$response->successful()) {
            return ['error' => 'HTTP ' . $response->status()];
        }

        $html = $response->body();
        $baseUrl = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $result = ['url' => $url];

        if (in_array($resource, ['all', 'css'])) {
            $result['stylesheets'] = $this->extractAndFetch($html, '/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)/i', $baseUrl, $url);
            $result['inline_styles'] = $this->extractInline($html, '/<style[^>]*>(.*?)<\/style>/si');
        }

        if (in_array($resource, ['all', 'js'])) {
            $result['scripts'] = $this->extractUrls($html, '/<script[^>]+src=["\']([^"\']+)/i', $baseUrl, $url);
        }

        if (in_array($resource, ['all', 'images'])) {
            $result['images'] = $this->extractUrls($html, '/<img[^>]+src=["\']([^"\']+)/i', $baseUrl, $url);
        }

        if (in_array($resource, ['all', 'meta'])) {
            $result['meta'] = $this->extractMeta($html);
        }

        if (in_array($resource, ['all', 'colors'])) {
            $result['colors'] = $this->extractColors($html, $result['inline_styles'] ?? '', $result['stylesheets'] ?? []);
        }

        if (in_array($resource, ['all', 'fonts'])) {
            $allCss = ($result['inline_styles'] ?? '') . ' ' . implode(' ', array_column($result['stylesheets'] ?? [], 'content'));
            $result['fonts'] = $this->extractFonts($allCss);
        }

        if ($resource === 'text') {
            $result['text'] = $this->extractText($html);
        }

        return $result;
    }

    private function extractAndFetch(string $html, string $pattern, string $baseUrl, string $pageUrl): array
    {
        $urls = $this->extractUrls($html, $pattern, $baseUrl, $pageUrl);
        $results = [];

        foreach (array_slice($urls, 0, 10) as $cssUrl) {
            try {
                $resp = Http::timeout(10)->withUserAgent('VelaBuild-AI-Helper/1.0')->get($cssUrl);
                if ($resp->successful()) {
                    $body = $resp->body();
                    $results[] = [
                        'url' => $cssUrl,
                        'content' => mb_substr($body, 0, self::MAX_BYTES),
                        'truncated' => strlen($body) > self::MAX_BYTES,
                    ];
                }
            } catch (\Throwable $e) {
                $results[] = ['url' => $cssUrl, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function extractUrls(string $html, string $pattern, string $baseUrl, string $pageUrl): array
    {
        preg_match_all($pattern, $html, $matches);
        $urls = [];
        foreach (array_unique($matches[1] ?? []) as $href) {
            $urls[] = $this->resolveUrl($href, $baseUrl, $pageUrl);
        }
        return $urls;
    }

    private function extractInline(string $html, string $pattern): string
    {
        preg_match_all($pattern, $html, $matches);
        return mb_substr(implode("\n\n", $matches[1] ?? []), 0, self::MAX_BYTES);
    }

    private function extractMeta(string $html): array
    {
        $meta = [];
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
            $meta['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }
        preg_match_all('/<meta\s[^>]*>/i', $html, $tags);
        foreach ($tags[0] as $tag) {
            $name = null;
            $content = null;
            if (preg_match('/(?:name|property)=["\']([^"\']+)["\']/', $tag, $n)) $name = $n[1];
            if (preg_match('/content=["\']([^"\']*)["\']/', $tag, $c)) $content = $c[1];
            if ($name && $content !== null) $meta[$name] = $content;
        }
        return $meta;
    }

    private function extractColors(string $html, string $inlineCss, array $stylesheets): array
    {
        $allCss = $inlineCss;
        foreach ($stylesheets as $sheet) {
            $allCss .= ' ' . ($sheet['content'] ?? '');
        }
        if (preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $allCss . ' ' . $html, $m)) {
            return array_values(array_unique(array_slice($m[0], 0, 30)));
        }
        return [];
    }

    private function extractFonts(string $css): array
    {
        $fonts = [];
        if (preg_match_all('/font-family\s*:\s*([^;}{]+)/i', $css, $m)) {
            foreach ($m[1] as $val) {
                $families = array_map(fn($f) => trim(trim($f), "'\""), explode(',', $val));
                $fonts = array_merge($fonts, $families);
            }
        }
        return array_values(array_unique(array_filter(array_slice($fonts, 0, 20))));
    }

    private function extractText(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<nav\b[^>]*>.*?<\/nav>/si', '', $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return mb_substr(trim($text), 0, 50_000);
    }

    private function resolveUrl(string $href, string $baseUrl, string $pageUrl): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) return $href;
        if (str_starts_with($href, '//')) return parse_url($pageUrl, PHP_URL_SCHEME) . ':' . $href;
        if (str_starts_with($href, '/')) return $baseUrl . $href;
        return rtrim(dirname($pageUrl), '/') . '/' . $href;
    }
}
