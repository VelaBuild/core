<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\AiChat\PageSectionExtractor;
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
            // Sections and raw markup are readable from plain HTTP too, and
            // they are the two the copy workflow depends on — refusing them
            // for want of a browser is what made copies coarse on every
            // install that never set CLOUDFLARE_BROWSER_RENDERING_URL.
            return match ($action) {
                'extract'  => $this->fallbackExtract($url),
                'sections' => $this->fallbackSections($url),
                'html'     => $this->fallbackHtml($url, $parameters),
                'design_tokens' => $this->fallbackDesignTokens($url),
                default    => ['error' => 'Browser rendering not configured. Set CLOUDFLARE_BROWSER_RENDERING_URL. Without it, use action "sections" (page outline), "html" (raw markup) or "extract" — all three fall back to HTTP fetch.'],
            };
        }

        return match ($action) {
            'extract' => $this->extract($renderer, $url),
            'sections' => $this->sections($renderer, $url),
            'design_tokens' => $this->designTokens($renderer, $url),
            'screenshot' => $this->screenshot($renderer, $url, $parameters),
            'html' => $this->getHtml($renderer, $url, $parameters),
            'evaluate' => $this->evaluate($renderer, $url, $parameters),
            'pdf' => $this->getPdf($renderer, $url),
            default => ['error' => "Unknown action: {$action}. Available: extract, sections, design_tokens, screenshot, html, evaluate, pdf"],
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

    /**
     * The page as an ordered list of sections — the blueprint a rebuild is
     * mapped from, and the count the finished page is checked against.
     */
    private function sections(BrowserRenderingService $renderer, string $url): array
    {
        $data = $this->unwrap($renderer->extractSections($url));
        if (!$data || empty($data['sections'])) {
            return $this->fallbackSections($url);
        }

        return ['success' => true, 'url' => $url, 'method' => 'browser']
            + $data
            + ['next_step' => $this->rebuildInstruction((int) ($data['section_count'] ?? 0))];
    }

    private function designTokens(BrowserRenderingService $renderer, string $url): array
    {
        $data = $this->unwrap($renderer->extractDesignTokens($url));
        if (!$data || empty($data['tokens'])) {
            return $this->fallbackDesignTokens($url);
        }
        return ['success' => true, 'url' => $url, 'method' => 'browser'] + $data;
    }

    /**
     * Browser-rendering workers differ over whether an evaluated script's
     * value comes back at the top level or nested under `result`. Accept both
     * rather than falling back to the plain HTTP path over a wrapper key.
     */
    private function unwrap(?array $data): ?array
    {
        if ($data && isset($data['result']) && is_array($data['result'])) {
            return $data['result'];
        }
        return $data;
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
            // Handed to the model as an actual picture, not just a path. A
            // stored file it can never look at taught it nothing about how
            // the page is laid out.
            '_images' => ScreenshotUrlTool::attachable($base64, 'Screenshot of ' . $url),
        ];
    }

    private function getHtml(BrowserRenderingService $renderer, string $url, array $params = []): array
    {
        $html = $renderer->html($url);
        if (!$html) {
            return $this->fallbackHtml($url, $params);
        }

        return $this->packHtml($url, $html, $params, 'browser');
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

    /** Section outline parsed from the served HTML, no browser involved. */
    private function fallbackSections(string $url): array
    {
        $html = $this->fetchHtml($url);
        if (isset($html['error'])) {
            return $html;
        }

        $data = app(PageSectionExtractor::class)->extract($html['body'], $url);
        if (empty($data['sections'])) {
            return ['error' => 'Could not find any sections in the served HTML. The page is probably rendered by JavaScript — set CLOUDFLARE_BROWSER_RENDERING_URL, or fall back to browse_url action "html".', 'url' => $url];
        }

        return ['success' => true, 'url' => $url, 'method' => 'http_fetch']
            + $data
            + [
                'note' => 'Parsed from the served HTML. Anything rendered by JavaScript after load is not included.',
                'next_step' => $this->rebuildInstruction((int) ($data['section_count'] ?? 0)),
            ];
    }

    private function fallbackDesignTokens(string $url): array
    {
        $result = app(FetchPageResourcesTool::class)->execute(['url' => $url, 'resource' => 'all']);
        return [
            'success' => true,
            'url'     => $url,
            'method'  => 'http_fetch',
            'colors'  => $result['colors'] ?? [],
            'fonts'   => $result['fonts'] ?? [],
            'note'    => 'Read out of the stylesheets rather than the rendered page — colours are unranked and there is no type scale. Set CLOUDFLARE_BROWSER_RENDERING_URL for computed tokens.',
        ];
    }

    private function fallbackHtml(string $url, array $params = []): array
    {
        $html = $this->fetchHtml($url);
        if (isset($html['error'])) {
            return $html;
        }

        return $this->packHtml($url, $html['body'], $params, 'http_fetch');
    }

    /**
     * Trim the markup to what describes the layout before capping it.
     *
     * Scripts and inline styles are most of the bytes on a modern page, so a
     * straight cut at the limit spent the whole budget on minified JavaScript
     * and stopped before the sections the model was sent to read.
     */
    private function packHtml(string $url, string $html, array $params, string $method): array
    {
        $limit = 200_000;
        $original = strlen($html);

        if (!empty($params['selector']) && is_string($params['selector'])) {
            $scoped = $this->scopeToSelector($html, $params['selector']);
            if ($scoped !== null) {
                $html = $scoped;
            }
        }

        if (empty($params['raw'])) {
            $html = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html) ?? $html;
            $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html) ?? $html;
            $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/si', '<svg/>', $html) ?? $html;
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        }

        $kept = mb_substr($html, 0, $limit);

        return array_filter([
            'success'   => true,
            'url'       => $url,
            'method'    => $method,
            'html'      => $kept,
            'truncated' => mb_strlen($html) > $limit,
            'stripped'  => empty($params['raw'])
                ? 'script/style/svg/comments removed (' . $original . ' bytes served, ' . strlen($html) . ' kept). Pass raw:true to keep them.'
                : null,
            'hint'      => mb_strlen($html) > $limit
                ? 'Still truncated. Use action "sections" for the outline, or pass a selector (e.g. "main", "#pricing") to read one part at a time.'
                : null,
        ], fn ($v) => $v !== null);
    }

    /** Cut the markup down to the first element matching a CSS-ish selector. */
    private function scopeToSelector(string $html, string $selector): ?string
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }

        $xpath = new \DOMXPath($doc);
        $selector = trim($selector);
        if (str_starts_with($selector, '#')) {
            $query = '//*[@id="' . substr($selector, 1) . '"]';
        } elseif (str_starts_with($selector, '.')) {
            $query = '//*[contains(concat(" ", normalize-space(@class), " "), " ' . substr($selector, 1) . ' ")]';
        } elseif (preg_match('/^[a-z][a-z0-9]*$/i', $selector)) {
            $query = '//' . strtolower($selector);
        } else {
            return null;
        }

        $node = $xpath->query($query)?->item(0);

        return $node ? $doc->saveHTML($node) : null;
    }

    /** @return array{body:string}|array{error:string} */
    private function fetchHtml(string $url): array
    {
        // Through FetchUrlTool so the SSRF guard and this-site exception apply
        // exactly as they do everywhere else.
        $result = app(FetchUrlTool::class)->execute(['url' => $url]);
        if (!empty($result['error'])) {
            return ['error' => $result['error']];
        }
        if (($result['status'] ?? 0) >= 400) {
            return ['error' => 'HTTP ' . $result['status'] . ' fetching ' . $url];
        }

        return ['body' => (string) ($result['body'] ?? '')];
    }

    private function rebuildInstruction(int $count): string
    {
        return "This page has {$count} sections. To copy it, build them in order — one add_row + add_block per section, "
            . 'using each section\'s suggested_block, its own heading/lead_text/buttons/images, and its repeated_items count. '
            . "When you are done, call get_page_blocks and check you have {$count} rows before reporting anything as finished.";
    }
}
