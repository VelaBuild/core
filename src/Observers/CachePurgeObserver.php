<?php

namespace VelaBuild\Core\Observers;

use VelaBuild\Core\Jobs\PurgeCloudflareCacheJob;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;

/**
 * Observes content mutations and dispatches Cloudflare purge jobs tagged
 * to the right cohort. Jobs run from the queue so admin saves aren't
 * blocked on CF latency.
 *
 * Tag taxonomy mirrors what {@see \VelaBuild\Core\Services\CacheTagger}
 * emits on the serving side — purging `page:42` invalidates the cached
 * response(s) that carry that tag, regardless of URL.
 *
 * Attached in VelaServiceProvider::boot() via ::observe() on each model.
 */
class CachePurgeObserver
{
    // ── Page ───────────────────────────────────────────────────────────

    public function savedPage(Page $page): void
    {
        $this->purge($this->pageTags($page));
    }

    public function deletedPage(Page $page): void
    {
        // Deletion gets the same tag set plus a paranoid `site` so listings
        // that referenced the page (menus, sitemaps, post lists) invalidate too.
        $tags = $this->pageTags($page);
        $tags[] = 'site';
        $this->purge($tags);
    }

    // ── PageRow / PageBlock (content inside a page) ────────────────────

    public function savedPageRow(PageRow $row): void
    {
        $this->purge($this->pageTagsForId($row->page_id));
    }

    public function deletedPageRow(PageRow $row): void
    {
        $this->purge($this->pageTagsForId($row->page_id));
    }

    public function savedPageBlock(PageBlock $block): void
    {
        $row = $block->row ?? ($block->page_row_id ? PageRow::find($block->page_row_id) : null);
        if ($row?->page_id) {
            $this->purge($this->pageTagsForId($row->page_id));
        }
    }

    public function deletedPageBlock(PageBlock $block): void
    {
        $this->savedPageBlock($block);
    }

    // ── Content (posts / articles / categories) ────────────────────────

    public function savedContent(Content $content): void
    {
        $this->purge($this->contentTags($content));
    }

    public function deletedContent(Content $content): void
    {
        $tags = $this->contentTags($content);
        $tags[] = 'site';
        $this->purge($tags);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /** @return array<int, string> */
    private function pageTags(Page $page): array
    {
        return array_values(array_filter([
            'page:' . $page->id,
            $page->slug ? 'page:slug:' . $page->slug : null,
            $page->locale ? 'locale:' . $page->locale : null,
        ]));
    }

    /** @return array<int, string> */
    private function pageTagsForId(int $pageId): array
    {
        $page = Page::find($pageId);
        return $page ? $this->pageTags($page) : ['page:' . $pageId];
    }

    /** @return array<int, string> */
    private function contentTags(Content $c): array
    {
        $tags = array_values(array_filter([
            'post:' . $c->id,
            $c->slug ? 'post:slug:' . $c->slug : null,
            !empty($c->locale) ? 'locale:' . $c->locale : null,
        ]));
        // Listing pages that include this post need to invalidate too —
        // category listings are the common one.
        if (!empty($c->category_id)) {
            $tags[] = 'category:' . $c->category_id;
        }
        return $tags;
    }

    private function purge(array $tags): void
    {
        $tags = array_values(array_filter($tags));
        if (empty($tags)) {
            return;
        }
        // Use the static dispatch — purgeTags() returns a Job instance,
        // and dispatchAfterResponse() is a static method on Dispatchable,
        // not an instance method. Queue via whatever QUEUE_CONNECTION is
        // configured; retries + backoff on the job cover transient CF errors.
        PurgeCloudflareCacheJob::dispatch([], $tags);
    }
}
