<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AgentDiscovery
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldSkip($request)) {
            return $response;
        }

        $response->headers->set('Link', '</.well-known/api-catalog>; rel="api-catalog"', false);
        $response->headers->set('Link', '</api/content>; rel="service-doc"; type="application/json"', false);
        $response->headers->set('Link', '</.well-known/mcp/server-card.json>; rel="mcp-server-card"', false);

        if ($this->wantsMarkdown($request) && $this->isHtmlResponse($response) && $response->isSuccessful()) {
            $html = $response->getContent();
            $markdown = $this->htmlToMarkdown($html);
            $tokenCount = (int) ceil(mb_strlen($markdown) / 4);

            $response->setContent($markdown);
            $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
            $response->headers->set('X-Markdown-Tokens', (string) $tokenCount);
        }

        return $response;
    }

    private function shouldSkip(Request $request): bool
    {
        $path = '/' . ltrim($request->path(), '/');

        return str_starts_with($path, '/admin') || str_starts_with($path, '/vela');
    }

    private function wantsMarkdown(Request $request): bool
    {
        return str_contains($request->header('Accept', ''), 'text/markdown');
    }

    private function isHtmlResponse(Response $response): bool
    {
        return str_contains($response->headers->get('Content-Type', ''), 'text/html');
    }

    private function htmlToMarkdown(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<nav\b[^>]*>.*?<\/nav>/si', '', $html);
        $html = preg_replace('/<footer\b[^>]*>.*?<\/footer>/si', '', $html);
        $html = preg_replace('/<header\b[^>]*>.*?<\/header>/si', "\n", $html);

        $html = preg_replace_callback(
            '/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/si',
            fn (array $m) => "\n" . str_repeat('#', (int) $m[1]) . ' ' . strip_tags($m[2]) . "\n",
            $html,
        );

        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<p[^>]*>/i', '', $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        $html = preg_replace('/<li[^>]*>/i', '- ', $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);

        $html = preg_replace_callback(
            '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si',
            fn (array $m) => '[' . strip_tags($m[2]) . '](' . $m[1] . ')',
            $html,
        );

        $html = preg_replace_callback(
            '/<img\s[^>]*alt=["\']([^"\']*)["\'][^>]*src=["\']([^"\']+)["\'][^>]*\/?>/si',
            fn (array $m) => '![' . $m[1] . '](' . $m[2] . ')',
            $html,
        );
        $html = preg_replace_callback(
            '/<img\s[^>]*src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*\/?>/si',
            fn (array $m) => '![' . $m[2] . '](' . $m[1] . ')',
            $html,
        );

        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace("/\n{3,}/", "\n\n", $html);

        return trim($html) . "\n";
    }
}
