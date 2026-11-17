<?php

namespace VelaBuild\Core\Mcp;

use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Models\Content;

/**
 * Sets an article's featured image (its `main_image`, shown on /posts and above the article)
 * from a picture a site-contract client such as Gemma publishes with it. Only real images are
 * kept, and a picture that cannot be fetched never stops the article itself.
 */
class FeaturedImage
{
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function attach(Content $article, string $url): void
    {
        try {
            $article->addMediaFromUrl($url, ...self::MIME_TYPES)->toMediaCollection('main_image');
        } catch (\Throwable $e) {
            Log::warning('MCP featured image could not be attached', ['article' => $article->getKey(), 'url' => $url, 'error' => $e->getMessage()]);
        }
    }
}
