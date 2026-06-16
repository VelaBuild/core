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
        $original = $slug;
        $i = 1;
        while (Page::where('slug', $slug)->exists()) {
            $slug = $original . '-' . $i++;
        }

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
