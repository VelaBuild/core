<?php

namespace VelaBuild\Core\Services;

use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * Keeps a visitor in the language they chose. On a translated page, every
 * link to another page of the site gets that language's prefix (/about ->
 * /fr/about), wherever the link came from: the menu, a button inside a
 * block, an article card. And each language-switcher link (marked
 * data-vela-lang) points at this same page in its language.
 *
 * "This site" is matched by host, not by the full root URL: behind a proxy
 * the app can see http:// while the links it wrote say https://.
 */
class LocalizedLinks
{
    /** First path segments that are not pages: admin, images, assets, files. */
    private const NOT_PAGES = [
        'vela', 'admin', 'imgp', 'imgr', 'storage', 'vendor', 'cdn-cgi', 'build', 'flags',
        'api', 'livewire', 'designsystem', 'sitemap.xml', 'robots.txt', 'manifest.json', 'feed',
    ];

    /**
     * @param string $locale   the page's language
     * @param string $pagePath the page's path without a language prefix ('' for home, 'about', ...)
     */
    public static function rewrite(string $html, string $locale, string $pagePath): string
    {
        $primary = (string) config('vela.primary_language', 'en');
        $locales = array_keys(LaravelLocalization::getSupportedLocales());
        if (count($locales) < 2 || !in_array($locale, $locales, true)) {
            return $html;
        }
        $hosts = self::hosts();
        $pagePath = trim($pagePath, '/');
        $pagePath = $pagePath === '' ? '' : '/' . $pagePath;

        return preg_replace_callback('/<a\b[^>]*>/i', function ($m) use ($locale, $primary, $locales, $hosts, $pagePath) {
            $tag = $m[0];
            if (preg_match('/\sdata-vela-lang="([^"]+)"/', $tag, $lang)) {
                return preg_replace_callback('/\shref="([^"]*)"/', function ($h) use ($lang, $primary, $pagePath) {
                    $origin = preg_match('#^(https?://[^/]+)#i', $h[1], $o) ? $o[1] : '';
                    $path = ($lang[1] === $primary ? '' : '/' . $lang[1]) . $pagePath;

                    return ' href="' . $origin . ($path === '' ? '/' : $path) . '"';
                }, $tag, 1);
            }
            if ($locale === $primary) {
                return $tag;
            }

            return preg_replace_callback('/\shref="([^"]*)"/', function ($h) use ($locale, $locales, $hosts) {
                return ' href="' . self::localize($h[1], $locale, $locales, $hosts) . '"';
            }, $tag, 1);
        }, $html) ?? $html;
    }

    /** /about -> /fr/about for a link to one of this site's pages; anything else unchanged. */
    public static function localize(string $href, string $locale, array $locales, array $hosts): string
    {
        if (preg_match('#^(https?://([^/?\#]+))(.*)$#i', $href, $m)) {
            if (!in_array(strtolower($m[2]), $hosts, true)) {
                return $href;   // another site
            }
            $origin = $m[1];
            $rest = $m[3];
        } elseif (str_starts_with($href, '/') && !str_starts_with($href, '//')) {
            $origin = '';
            $rest = $href;
        } else {
            return $href;   // #anchors, mailto:, tel:, relative
        }
        $path = (string) preg_replace('/[?#].*$/s', '', $rest);
        $suffix = substr($rest, strlen($path));
        $first = explode('/', ltrim($path, '/'))[0];
        if (in_array($first, $locales, true) || in_array($first, self::NOT_PAGES, true) || str_contains($first, '.')) {
            return $href;
        }

        return $origin . '/' . $locale . rtrim($path, '/') . $suffix;
    }

    /** @return list<string> this site's host names */
    private static function hosts(): array
    {
        $hosts = [];
        foreach ([url('/'), (string) config('app.url')] as $u) {
            $h = parse_url($u, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $hosts[] = strtolower($h) . ((($p = parse_url($u, PHP_URL_PORT)) !== null) ? ':' . $p : '');
            }
        }

        return array_values(array_unique($hosts));
    }
}
