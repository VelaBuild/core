<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Site quality / safety / standards scanner.
 *
 *   php artisan vela:checks              # defaults to /home
 *   php artisan vela:checks pricing      # any page slug
 *   php artisan vela:checks https://vela.build/pricing   # any URL
 *
 * Reads from the committed static cache first (resources/static/…/index.html),
 * falls back to fetching the URL over HTTP. Runs a series of checks
 * (links, images, meta, headings, fonts, cookies, W3C) and prints pass /
 * warn / fail per check. Exits non-zero when any check fails.
 */
class RunChecks extends Command
{
    protected $signature = 'vela:checks
        {page=home : Page slug (home, pricing, builder…) or full URL}
        {--no-w3c : Skip the W3C nu-validator HTTP call}
        {--strict : Treat warnings as failures for exit code}';

    protected $description = 'Scan a rendered page for quality, safety, and standards violations.';

    private int $pass = 0;
    private int $warn = 0;
    private int $fail = 0;

    public function handle(): int
    {
        $arg = $this->argument('page');

        [$html, $sourceLabel, $url] = $this->loadPage($arg);
        if ($html === null) {
            $this->error("Could not load page: {$arg}");
            return 2;
        }

        $this->line("\n<fg=cyan;options=bold>vela:checks</> <fg=gray>— {$sourceLabel}</>");
        $this->line(str_repeat('─', 72));

        $this->checkDoctypeAndLang($html);
        $this->checkViewportAndCharset($html);
        $this->checkTitleAndMeta($html);
        $this->checkCanonicalAndOG($html);
        $this->checkHeadings($html);
        $this->checkImages($html);
        $this->checkLinks($html, $url);
        $this->checkFonts($html);
        $this->checkStylesInBody($html);
        $this->checkCookieConsent($html);
        $this->checkMixedContent($html);
        $this->checkAssetSizes($html);

        if (!$this->option('no-w3c')) {
            $this->checkW3C($html);
        }

        $this->line(str_repeat('─', 72));
        $this->line(sprintf(
            '<fg=green>%d pass</> · <fg=yellow>%d warn</> · <fg=red>%d fail</>',
            $this->pass, $this->warn, $this->fail
        ));

        $failExit = $this->fail > 0 || ($this->option('strict') && $this->warn > 0);
        return $failExit ? 1 : 0;
    }

    // ============================================================
    // Page loaders
    // ============================================================

    private function loadPage(string $arg): array
    {
        // Full URL form — fetch over HTTP.
        if (preg_match('#^https?://#', $arg)) {
            $res = Http::withOptions(['verify' => false])->timeout(15)->get($arg);
            if (!$res->ok()) return [null, "HTTP {$res->status()}", $arg];
            return [$res->body(), "URL {$arg} (HTTP {$res->status()})", $arg];
        }

        // Try static cache first — same bytes that ship to prod.
        $slug = trim($arg, '/');
        if ($slug === '' || $slug === 'home') {
            $path = resource_path('static/home/index.html');
            $label = 'resources/static/home/index.html';
        } elseif (in_array($slug, ['posts', 'categories'])) {
            $path = resource_path("static/{$slug}/index.html");
            $label = "resources/static/{$slug}/index.html";
        } else {
            $path = resource_path("static/pages/{$slug}/index.html");
            $label = "resources/static/pages/{$slug}/index.html";
        }

        if (is_file($path)) {
            return [file_get_contents($path), $label, url('/' . ltrim($slug, '/'))];
        }

        // Fall through — try HTTP on APP_URL.
        $url = rtrim((string) config('app.url'), '/') . '/' . ltrim($slug, '/');
        $res = Http::withOptions(['verify' => false])->timeout(15)->get($url);
        if (!$res->ok()) return [null, "no static file, HTTP {$res->status()}", $url];
        return [$res->body(), "URL {$url} (HTTP {$res->status()})", $url];
    }

    // ============================================================
    // Individual checks
    // ============================================================

    private function checkDoctypeAndLang(string $html): void
    {
        $this->report('Doctype', str_starts_with(ltrim($html), '<!DOCTYPE html>') || stripos(substr($html, 0, 50), '<!doctype html') !== false);
        $this->report('<html lang="…">', (bool) preg_match('/<html[^>]*\blang\s*=\s*["\'][a-zA-Z-]+["\']/', $html));
    }

    private function checkViewportAndCharset(string $html): void
    {
        $this->report('meta charset utf-8', (bool) preg_match('/<meta[^>]*charset\s*=\s*["\']?utf-8/i', $html));
        $this->report('meta viewport', (bool) preg_match('/<meta[^>]*name\s*=\s*["\']viewport["\']/i', $html));
    }

    private function checkTitleAndMeta(string $html): void
    {
        $titleOk = preg_match('#<title>(.*?)</title>#is', $html, $m);
        if ($titleOk) {
            $t = trim(html_entity_decode($m[1]));
            $len = mb_strlen($t);
            if ($t === '') {
                $this->report('title', false, 'empty');
            } elseif ($len > 70) {
                $this->report('title', 'warn', "{$len} chars (>60 recommended)");
            } else {
                $this->report('title', true, "\"{$t}\" ({$len})");
            }
        } else {
            $this->report('title', false, 'missing');
        }

        if (preg_match('#<meta[^>]*name\s*=\s*["\']description["\'][^>]*content\s*=\s*["\']([^"\']*)["\']#i', $html, $m)) {
            $d = trim(html_entity_decode($m[1]));
            $len = mb_strlen($d);
            if ($d === '') {
                $this->report('meta description', false, 'empty');
            } elseif ($len < 50) {
                $this->report('meta description', 'warn', "{$len} chars (50–160 ideal)");
            } elseif ($len > 170) {
                $this->report('meta description', 'warn', "{$len} chars (>160 gets truncated)");
            } else {
                $this->report('meta description', true, "{$len} chars");
            }
        } else {
            $this->report('meta description', false, 'missing');
        }
    }

    private function checkCanonicalAndOG(string $html): void
    {
        $canon = preg_match('#<link[^>]*rel\s*=\s*["\']canonical["\'][^>]*href\s*=\s*["\']([^"\']+)["\']#i', $html, $m);
        if ($canon) {
            $url = $m[1];
            if (str_contains($url, '/admin/') || str_contains($url, '/cache/')) {
                $this->report('canonical', false, "points at admin URL: {$url}");
            } else {
                $this->report('canonical', true, $url);
            }
        } else {
            $this->report('canonical', false, 'missing');
        }

        foreach (['og:title', 'og:description', 'og:image', 'og:url'] as $prop) {
            $quoted = preg_quote($prop, '#');
            $has = preg_match('#<meta[^>]*property\s*=\s*["\']' . $quoted . '["\']#i', $html);
            $this->report("OpenGraph {$prop}", (bool) $has);
        }
    }

    private function checkHeadings(string $html): void
    {
        $h1 = preg_match_all('#<h1\b[^>]*>(.*?)</h1>#is', $html, $m);
        if ($h1 === 0) {
            $this->report('<h1>', false, 'no H1');
        } elseif ($h1 === 1) {
            $text = trim(strip_tags($m[1][0]));
            $this->report('<h1>', true, "\"{$text}\"");
        } else {
            $this->report('<h1>', 'warn', "{$h1} H1 tags (1 recommended)");
        }
    }

    private function checkImages(string $html): void
    {
        preg_match_all('#<img\b([^>]*)>#i', $html, $matches);
        $imgs = $matches[1] ?? [];
        $total = count($imgs);
        if ($total === 0) {
            $this->report('images', 'warn', 'no <img> tags');
            return;
        }

        $missingAlt = 0;
        $missingDims = 0;
        $unoptimized = 0;
        foreach ($imgs as $attrs) {
            if (!preg_match('#\balt\s*=#i', $attrs)) $missingAlt++;
            if (!preg_match('#\bwidth\s*=#i', $attrs) || !preg_match('#\bheight\s*=#i', $attrs)) $missingDims++;
            if (preg_match('#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $attrs, $m)) {
                $src = $m[1];
                if (!str_contains($src, '/imgp/') && !str_contains($src, '/imgr/')
                    && !str_starts_with($src, 'data:')
                    && !preg_match('#^https?://(?!' . preg_quote(parse_url(config('app.url'), PHP_URL_HOST), '#') . ')#', $src)) {
                    // Local image not going through the optimiser (cross-origin is exempt).
                    if (str_contains($src, parse_url(config('app.url'), PHP_URL_HOST) ?? '')
                        || !preg_match('#^https?://#', $src)) {
                        $unoptimized++;
                    }
                }
            }
        }

        $this->report('image alt attributes', $missingAlt === 0 ? true : ($missingAlt < $total / 2 ? 'warn' : false),
            $missingAlt === 0 ? "all {$total} images have alt" : "{$missingAlt}/{$total} missing");
        $this->report('image width/height', $missingDims === 0 ? true : 'warn',
            $missingDims === 0 ? "all {$total} have explicit dims" : "{$missingDims}/{$total} missing (CLS risk)");
        $this->report('image optimisation', $unoptimized === 0 ? true : false,
            $unoptimized === 0 ? "all local images go through /imgp/ or /imgr/" : "{$unoptimized} raw <img> (should use vela_image())");
    }

    private function checkLinks(string $html, string $url): void
    {
        // Links in BODY only (head has hreflang/canonical that are legitimately absolute).
        $body = preg_match('#<body\b[^>]*>(.*?)</body>#is', $html, $m) ? $m[1] : $html;

        preg_match_all('#<a\b[^>]*\bhref\s*=\s*["\']([^"\']*)["\']#i', $body, $matches);
        $hrefs = $matches[1] ?? [];

        $barePath = 0;
        $jsHref = 0;
        $empty = 0;
        foreach ($hrefs as $h) {
            if ($h === '' || $h === '#') { $empty++; continue; }
            if (str_starts_with($h, 'javascript:')) { $jsHref++; continue; }
            // Bare root-relative path (not an anchor). These break on subdir installs.
            if (preg_match('#^/[^/]#', $h) && !str_starts_with($h, '//')) {
                $barePath++;
            }
        }

        $total = count($hrefs);
        $this->report('links (count)', $total > 0 ? true : 'warn', "{$total} <a> in body");
        $this->report('links: no bare /path', $barePath === 0, $barePath === 0 ? '' : "{$barePath} link(s) — must use url()/route()");
        $this->report('links: no empty href="#"', $empty === 0 ? true : 'warn', $empty === 0 ? '' : "{$empty} placeholder href");
        $this->report('links: no javascript:', $jsHref === 0, $jsHref === 0 ? '' : "{$jsHref} javascript: href");
    }

    private function checkFonts(string $html): void
    {
        $hasGoogle = str_contains($html, 'fonts.googleapis.com') || str_contains($html, 'fonts.gstatic.com');
        $this->report('GDPR: no Google Fonts', !$hasGoogle, $hasGoogle ? 'fonts.googleapis.com / fonts.gstatic.com present — use fonts.bunny.net' : 'using fonts.bunny.net');
    }

    private function checkStylesInBody(string $html): void
    {
        if (preg_match('#<body\b[^>]*>(.*?)</body>#is', $html, $m)) {
            $body = $m[1];
            $count = preg_match_all('#<style\b[^>]*>#i', $body);
            $this->report('HTML5: no <style> in body', $count === 0, $count === 0 ? '' : "{$count} inline <style> (move to head or bundle)");
        }
    }

    private function checkCookieConsent(string $html): void
    {
        if (!config('vela.gdpr.enabled')) {
            $this->report('GDPR cookie consent', 'warn', 'disabled in config — skipping');
            return;
        }
        $has = str_contains($html, 'id="vela-consent"');
        $this->report('GDPR cookie consent banner', $has, $has ? '' : 'banner markup missing');
    }

    private function checkMixedContent(string $html): void
    {
        // Flag http:// asset URLs only if the canonical URL is https.
        $canonHttps = preg_match('#<link[^>]*rel\s*=\s*["\']canonical["\'][^>]*href\s*=\s*["\']https://#i', $html);
        if (!$canonHttps) {
            $this->report('mixed content', 'warn', 'canonical not https — skipping');
            return;
        }
        $badCount = 0;
        foreach (['src', 'href'] as $attr) {
            preg_match_all('#\b' . $attr . '\s*=\s*["\'](http://[^"\']+)["\']#i', $html, $matches);
            $badCount += count($matches[1] ?? []);
        }
        $this->report('mixed content (http assets)', $badCount === 0, $badCount === 0 ? '' : "{$badCount} http:// asset(s) on an https page");
    }

    private function checkAssetSizes(string $html): void
    {
        preg_match_all('#<link[^>]*rel\s*=\s*["\']stylesheet["\'][^>]*href\s*=\s*["\']([^"\']+)["\']#i', $html, $cssMatches);
        preg_match_all('#<script[^>]*src\s*=\s*["\']([^"\']+)["\']#i', $html, $jsMatches);
        $this->report('CSS <link> count', count($cssMatches[1]) <= 6 ? true : 'warn',
            count($cssMatches[1]) . ' files (≤6 ideal for perf)');
        $this->report('script count (external)', count($jsMatches[1]) <= 6 ? true : 'warn',
            count($jsMatches[1]) . ' files');
    }

    private function checkW3C(string $html): void
    {
        try {
            $res = Http::withHeaders(['Content-Type' => 'text/html; charset=utf-8'])
                ->timeout(30)
                ->withBody($html, 'text/html; charset=utf-8')
                ->post('https://validator.w3.org/nu/?out=json');
        } catch (\Throwable $e) {
            $this->report('W3C validation', 'warn', 'validator unreachable: ' . $e->getMessage());
            return;
        }
        if (!$res->ok()) {
            $this->report('W3C validation', 'warn', "validator HTTP {$res->status()}");
            return;
        }
        $json = $res->json();
        $messages = $json['messages'] ?? [];
        $errors = array_filter($messages, fn($m) => ($m['type'] ?? '') === 'error');
        $warnings = array_filter($messages, fn($m) => ($m['type'] ?? '') === 'info' && ($m['subType'] ?? '') === 'warning');

        $this->report(
            'W3C validation',
            count($errors) === 0,
            count($errors) === 0
                ? (count($warnings) === 0 ? 'perfect — 0 errors, 0 warnings' : count($warnings) . ' warning(s)')
                : count($errors) . ' error(s)'
        );

        if (count($errors) > 0 || ($this->output->isVerbose() && count($warnings) > 0)) {
            foreach (array_slice($errors, 0, 10) as $e) {
                $this->line("    <fg=red>×</> line {$e['lastLine']}: " . ($e['message'] ?? ''));
            }
            if (count($errors) > 10) {
                $this->line('    <fg=gray>… ' . (count($errors) - 10) . ' more</>');
            }
        }
    }

    // ============================================================
    // Reporter
    // ============================================================

    private function report(string $name, bool|string $result, string $detail = ''): void
    {
        if ($result === true) {
            $this->pass++;
            $glyph = '<fg=green>✓</>';
            $color = 'default';
        } elseif ($result === 'warn') {
            $this->warn++;
            $glyph = '<fg=yellow>⚠</>';
            $color = 'yellow';
        } else {
            $this->fail++;
            $glyph = '<fg=red>✗</>';
            $color = 'red';
        }
        $line = sprintf("  %s  <options=bold>%s</>", $glyph, $name);
        if ($detail !== '') {
            $line .= " <fg=gray>— {$detail}</>";
        }
        $this->line($line);
    }
}
