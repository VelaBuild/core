<?php

use VelaBuild\Core\Services\CacheTagger;

if (!function_exists('cache_tag')) {
    /**
     * Register one or more Cloudflare Cache-Tag values for the current
     * response. Tags are accumulated across the request and written into
     * a single `Cache-Tag: a,b,c` header by the EmitCacheTags middleware.
     *
     * @param string|array<int, string> $tag  e.g. 'page:42' or ['page:42', 'locale:en']
     */
    function cache_tag(string|array $tag): void
    {
        /** @var CacheTagger $tagger */
        $tagger = app(CacheTagger::class);
        $tagger->add($tag);
    }
}
