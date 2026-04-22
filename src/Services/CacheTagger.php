<?php

namespace VelaBuild\Core\Services;

/**
 * Request-scoped collector for Cloudflare `Cache-Tag` response headers.
 *
 * Controllers and views call {@see add()} (via the cache_tag() helper) to
 * register tags that apply to the current response. The EmitCacheTags
 * middleware flushes the accumulated set into a single comma-separated
 * `Cache-Tag` header at the end of the request.
 *
 * Tag taxonomy (stable — don't change these without a purge-all on deploy):
 *   site                         every public response
 *   page:<id>                    a CMS page, identified by primary key
 *   page:slug:<slug>             same page, identified by slug
 *   post:<id> / post:slug:<slug> blog articles
 *   category:<id>                content category page
 *   locale:<code>                all content for a locale
 *   template:<name>              template-level styling
 *   store:product:<id>           store product detail page
 *   store:category:<id>          store category listing
 *   store:tag:<id>               store tag listing
 *   shop                         store storefront index
 *
 * Cache-Tag purge availability depends on your Cloudflare plan tier and has
 * been expanding over time — check your zone's dashboard for what's enabled.
 * The header itself is harmless to emit regardless; if purge-by-tag isn't
 * available on a given zone, {@see CloudflareService::purgeUrls} still works.
 */
class CacheTagger
{
    /** @var array<string, true> used as a set for dedup */
    private array $tags = [];

    public function add(string|array $tag): void
    {
        foreach ((array) $tag as $t) {
            $t = $this->sanitise($t);
            if ($t !== '') {
                $this->tags[$t] = true;
            }
        }
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->tags);
    }

    public function clear(): void
    {
        $this->tags = [];
    }

    public function header(): ?string
    {
        $tags = $this->all();
        if (empty($tags)) {
            return null;
        }
        // Cloudflare's documented limits: up to 16KB header, up to 1000 tags.
        // We cap at 900 to leave headroom and truncate if the caller went wild.
        if (count($tags) > 900) {
            $tags = array_slice($tags, 0, 900);
        }
        return implode(',', $tags);
    }

    /**
     * Cloudflare tags: printable ASCII only, no whitespace or control chars,
     * <= 1024 bytes each. We keep `:` for namespacing but strip anything
     * that could break the header.
     */
    private function sanitise(string $tag): string
    {
        $tag = trim($tag);
        // Remove everything except letters, digits, and a handful of safe delimiters.
        $tag = preg_replace('/[^A-Za-z0-9._:\-\/]/', '', $tag) ?? '';
        return substr($tag, 0, 200);
    }
}
