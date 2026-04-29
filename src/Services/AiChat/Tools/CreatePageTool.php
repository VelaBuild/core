<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Models\PageBlock;
use Illuminate\Support\Str;

class CreatePageTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $title = $parameters['title'] ?? null;
        $content = $parameters['content'] ?? '';
        $status = $parameters['status'] ?? 'draft';

        if (!$title) {
            return ['error' => 'Title parameter is required'];
        }

        // Refuse to create a duplicate. AI should call list_pages first,
        // then edit_page_content / set_page_blocks on the existing record
        // instead of stacking copies.
        $existing = Page::whereRaw('LOWER(title) = ?', [strtolower(trim($title))])->first();
        if ($existing) {
            return [
                'error'         => "A page titled '{$existing->title}' already exists (id={$existing->id}, slug={$existing->slug}). Use edit_page_content or set_page_blocks to update it.",
                'existing_id'   => $existing->id,
                'existing_slug' => $existing->slug,
            ];
        }

        $slug = Str::slug($title);
        $original = $slug;
        $i = 1;
        while (Page::where('slug', $slug)->exists()) {
            $slug = $original . '-' . $i++;
        }

        $page = Page::create([
            'title'  => $title,
            'slug'   => $slug,
            'status' => $status,
            'locale' => config('app.locale', 'en'),
        ]);

        // Create a single text row + block with the provided content
        if ($content) {
            $row = PageRow::create([
                'page_id'      => $page->id,
                'order_column' => 1,
            ]);

            PageBlock::create([
                'page_row_id'   => $row->id,
                'column_index'  => 0,
                'column_width'  => 12,
                'order_column'  => 1,
                'type'          => 'text',
                'content'       => ['body' => $content],
            ]);
        }

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
            ],
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
