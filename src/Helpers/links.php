<?php

use Illuminate\Support\Str;

if (!function_exists('vela_external_link_attrs')) {
    /**
     * Decide link target/rel attributes for a content link.
     *
     * Returns ` target="_blank" rel="noopener noreferrer"` ONLY for links that
     * leave the site (a different domain). Same-domain links, root-relative
     * links, on-page anchors (`#…`), and non-http schemes (mailto:, tel:) open
     * in the same tab — so a CTA pointing at `#audit-form` or `/contact` never
     * spawns a new tab, while a link to another site does.
     *
     * Echo the result directly inside an <a> tag:
     *   <a href="{{ $url }}"{!! vela_external_link_attrs($url) !!}>…</a>
     */
    function vela_external_link_attrs(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        // On-page anchor or root-relative path → same site, same tab.
        if ($url[0] === '#' || $url[0] === '/') {
            return '';
        }

        // Only absolute http(s) URLs can be "external". mailto:, tel:,
        // protocol-relative without a host, and plain relative paths stay local.
        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }

        $linkHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($linkHost === '') {
            return '';
        }

        // Compare against the configured site host. config('app.url') is the
        // canonical, config-cache-safe source and is the live domain on the
        // deployed site (pushgit rewrites APP_URL → LIVE_URL at deploy).
        $siteHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        $strip = fn (string $h): string => (string) preg_replace('/^www\./', '', $h);

        if ($siteHost !== '' && $strip($linkHost) === $strip($siteHost)) {
            return '';
        }

        return ' target="_blank" rel="noopener noreferrer"';
    }
}
