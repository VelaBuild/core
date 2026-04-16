<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Models\Content;

class MediaReplacementService
{
    /**
     * Replace old URL with new URL in all content tables.
     * Returns total number of affected rows.
     */
    public function replaceUrls(string $oldUrl, string $newUrl): int
    {
        $total = 0;

        // 1. vela_articles.content
        $total += DB::update(
            "UPDATE vela_articles SET content = REPLACE(content, ?, ?) WHERE content LIKE ? AND deleted_at IS NULL",
            [$oldUrl, $newUrl, '%' . $oldUrl . '%']
        );

        // 2. vela_articles.description
        $total += DB::update(
            "UPDATE vela_articles SET description = REPLACE(description, ?, ?) WHERE description LIKE ? AND deleted_at IS NULL",
            [$oldUrl, $newUrl, '%' . $oldUrl . '%']
        );

        // 3. vela_page_blocks.content
        $total += DB::update(
            "UPDATE vela_page_blocks SET content = REPLACE(content, ?, ?) WHERE content LIKE ?",
            [$oldUrl, $newUrl, '%' . $oldUrl . '%']
        );

        // 4. vela_page_blocks.settings
        $total += DB::update(
            "UPDATE vela_page_blocks SET settings = REPLACE(settings, ?, ?) WHERE settings LIKE ?",
            [$oldUrl, $newUrl, '%' . $oldUrl . '%']
        );

        Log::info("Media URL replacement: '$oldUrl' -> '$newUrl', affected $total rows");

        return $total;
    }

    /**
     * Count how many rows reference this URL across content tables.
     */
    public function countReferences(string $url): int
    {
        $count = DB::table('vela_articles')
            ->where('content', 'LIKE', '%' . $url . '%')
            ->whereNull('deleted_at')
            ->count();

        $count += DB::table('vela_page_blocks')
            ->where(function ($q) use ($url) {
                $q->where('content', 'LIKE', '%' . $url . '%')
                  ->orWhere('settings', 'LIKE', '%' . $url . '%');
            })
            ->count();

        return $count;
    }

    /**
     * Find Content records that reference this URL.
     */
    public function findContentReferences(string $url): Collection
    {
        return Content::where('content', 'LIKE', '%' . $url . '%')
            ->orWhere('description', 'LIKE', '%' . $url . '%')
            ->select('id', 'title', 'slug')
            ->withTrashed()
            ->get();
    }
}
