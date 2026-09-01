<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use Illuminate\Support\Str;

class CreatePageTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $title = $parameters['title'] ?? null;
        $status = $parameters['status'] ?? 'draft';

        if (!$title) {
            return ['error' => 'Title parameter is required'];
        }

        // Refuse to create a duplicate. AI should call list_pages first,
        // then edit_page_content / add_block on the existing record
        // instead of stacking copies.
        $existing = Page::whereRaw('LOWER(title) = ?', [strtolower(trim($title))])->first();
        if ($existing) {
            return [
                'error'         => "A page titled '{$existing->title}' already exists (id={$existing->id}, slug={$existing->slug}). Use edit_page_content or add_block to update it.",
                'existing_id'   => $existing->id,
                'existing_slug' => $existing->slug,
            ];
        }

        // Honour an explicit slug when given (so the AI controls the URL),
        // otherwise derive one from the title. De-dupe either way.
        $slug = Str::slug(!empty($parameters['slug']) ? $parameters['slug'] : $title);

        // Str::slug drops every non-ASCII character, so a title written in Thai,
        // Chinese, Arabic, … slugifies to an empty string. Creating the page
        // anyway yields a record with no URL that can never be opened, so ask
        // for a latin slug instead — matching update_page's behaviour.
        if ($slug === '') {
            $source = !empty($parameters['slug']) ? $parameters['slug'] : $title;
            return [
                'error' => "Cannot derive a URL from '{$source}' — it contains no latin letters or digits, "
                    . 'and page URLs must match a-z, 0-9 and hyphens. Call create_page again with an explicit '
                    . "latin `slug` (e.g. slug='contact-us' for a page titled 'Contact Us'); keep `title` in the "
                    . "user's own language.",
            ];
        }

        // A design build is handed the page it is to fill. Asked for the address
        // that page already holds, this used to hand back "design-preview-1" —
        // a second, unlisted copy of the same site that nobody ever looks at,
        // built with the turns the real page needed.
        if ($slug === \VelaBuild\Core\Commands\DesignToSite::PREVIEW_SLUG
            || preg_match('/^' . preg_quote(\VelaBuild\Core\Commands\DesignToSite::PREVIEW_SLUG, '/') . '-\\d+$/', $slug)) {
            $preview = Page::where('slug', \VelaBuild\Core\Commands\DesignToSite::PREVIEW_SLUG)->first();

            return [
                'error' => 'The design preview page is not created by hand — the build is given it. '
                    . ($preview
                        ? "It already exists (id={$preview->id}, slug={$preview->slug}); build onto that page with add_row / add_designed_section."
                        : 'Run the design build, which creates it.'),
                'existing_id'   => $preview?->id,
                'existing_slug' => $preview?->slug,
            ];
        }

        // An explicit slug is an address the caller means to use. Quietly moving
        // it to "pricing-1" leaves a menu item, a button or a following call
        // pointing at "pricing", which is somebody else's page.
        if (!empty($parameters['slug'])) {
            $taken = Page::where('slug', $slug)->first();
            if ($taken) {
                return [
                    'error' => "The URL '{$slug}' already belongs to '{$taken->title}' (id={$taken->id}). "
                        . 'Use add_row / add_block on that page, or call create_page again with a different slug.',
                    'existing_id'   => $taken->id,
                    'existing_slug' => $taken->slug,
                ];
            }
        }

        $original = $slug;
        $i = 1;
        // Only a live page owns an address. A deleted one is asked to give it
        // up below, so it must not push this page onto "contact-us-1".
        while (Page::where('slug', $slug)->exists()) {
            $slug = $original . '-' . $i++;
        }
        Page::releaseSlugFromTrash($slug, (string) config('vela.primary_language', 'en'));

        // Create the page SHELL only. Building the layout is the AI's job:
        // it follows up with add_row + add_block (hero / cta / text / ...), which
        // is how the page builder is meant to be used. Auto-stuffing a single
        // text block produced flat, hard-to-edit pages.
        $page = Page::create([
            'title'  => $title,
            'slug'   => $slug,
            'status' => $status,
            'locale' => config('app.locale', 'en'),
        ]);

        if ($actionLog) {
            $actionLog->update([
                'previous_state' => ['created_id' => $page->id],
            ]);
        }

        return [
            'success' => true,
            'page' => [
                'id'    => $page->id,
                'title' => $page->title,
                'slug'  => $page->slug,
                'url'   => url('/' . $page->slug),
            ],
            'next_step' => 'Page created empty. Use add_row + add_block to build its layout, or edit_page_content for a simple markdown body.',
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state || !isset($state['created_id'])) {
            throw new \RuntimeException('No previous state to restore.');
        }

        Page::find($state['created_id'])?->delete();
    }
}
