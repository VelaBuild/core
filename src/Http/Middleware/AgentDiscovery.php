<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agent Discovery middleware.
 *
 * Adds RFC 8288 Link headers so AI agents and automated clients can
 * discover the site's API catalog and service documentation.  When an
 * agent explicitly requests text/markdown via the Accept header on a
 * public page, the HTML body is converted to a lightweight markdown
 * representation.
 *
 * Only applies to public-facing routes — admin and Vela panel URLs
 * are excluded.
 *
 * @see https://isitagentready.com
 */
class AgentDiscovery
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldSkip($request)) {
            return $response;
        }

        // RFC 8288 Link headers for agent discovery.
        $response->headers->set('Link', '</.well-known/api-catalog>; rel="api-catalog"', false);
        $response->headers->set('Link', '</api/content>; rel="service-doc"; type="application/json"', false);

        // Markdown content negotiation — only on public page routes.
        if ($this->wantsMarkdown($request) && $this->isPublicPage($request) && $this->isHtmlResponse($response)) {
            $html = $response->getContent();
            $markdown = $this->htmlToMarkdown($html);

            $response->setContent($markdown);
            $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
        }

        return $response;
    }

    /**
     * Skip admin and Vela panel routes entirely.
     */
    private function shouldSkip(Request $request): bool
    {
        $path = '/' . ltrim($request->path(), '/');

        return str_starts_with($path, '/admin') || str_starts_with($path, '/vela');
    }

    /**
     * Does the client explicitly accept text/markdown?
     */
    private function wantsMarkdown(Request $request): bool
    {
        return str_contains($request->header('Accept', ''), 'text/markdown');
    }

    /**
     * Is this a public page route (vela.public.*)?
     */
    private function isPublicPage(Request $request): bool
    {
        $route = $request->route();

        if (!$route) {
            return false;
        }

        $name = $route->getName();

        return $name !== null && str_starts_with($name, 'vela.public.');
    }

    /**
     * Does the response carry HTML content?
     */
    private function isHtmlResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/html');
    }

    /**
     * Convert an HTML string to a simple markdown representation.
     *
     * This is intentionally lightweight — it handles the most common
     * structural elements rather than attempting a full HTML-to-Markdown
     * conversion.
     */
    private function htmlToMarkdown(string $html): string
    {
        // Headings → markdown markers.
        $html = preg_replace_callback(
            '/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/si',
            fn (array $m) => "\n" . str_repeat('#', (int) $m[1]) . ' ' . strip_tags($m[2]) . "\n",
            $html,
        );

        // Paragraphs → double newlines.
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<p[^>]*>/i', '', $html);

        // Line breaks.
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // List items → dash prefix.
        $html = preg_replace('/<li[^>]*>/i', '- ', $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);

        // Anchors → [text](url).
        $html = preg_replace_callback(
            '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si',
            fn (array $m) => '[' . strip_tags($m[2]) . '](' . $m[1] . ')',
            $html,
        );

        // Strip remaining tags.
        $html = strip_tags($html);

        // Decode HTML entities.
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse multiple blank lines into a maximum of two newlines.
        $html = preg_replace("/\n{3,}/", "\n\n", $html);

        return trim($html) . "\n";
    }
}
