<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;

class AddBlockTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $rowId = $parameters['row_id'] ?? null;
        if (!$rowId) {
            return ['error' => 'row_id is required — add a row first (add_row) or get one from get_page_blocks.'];
        }
        $row = PageRow::find($rowId);
        if (!$row) {
            return ['error' => "Row {$rowId} not found."];
        }

        $type = $parameters['type'] ?? null;
        $blocks = app(\VelaBuild\Core\Vela::class)->blocks();
        $registered = array_keys($blocks->all());
        if (!$type || !in_array($type, $registered, true)) {
            return [
                'error'          => "Unknown block type '" . ($type ?? '') . "'. Call list_block_types for valid types and their content shape.",
                'available_types' => $registered,
            ];
        }

        // Some blocks render on the public site but have no editor in the
        // admin: put one on a page and its owner is shown "Unknown block
        // type" and can never change a word of it. Adding one is worse than
        // not having the section at all, so it is refused here.
        if (!$blocks->isEditable($type)) {
            return [
                'error' => "The '{$type}' block renders on the site but cannot be edited in the admin — anyone opening the page would see \"Unknown block type\" and could not change it. Build the section from an editable block instead.",
                'editable_types' => $blocks->editableNames(),
            ];
        }

        // The text block is EditorJS-backed: store the canonical {blocks:[...]}
        // shape so it renders in the page builder, the editor, and the live site.
        $content = $parameters['content'] ?? null;
        if ($type === 'text') {
            $content = MarkdownToEditorJs::textBlockContent($content);
        } elseif ($error = $this->validateBlockContent($type, $content)) {
            return $error;
        }

        if ($error = $this->validateBlockSettings($type, $parameters['settings'] ?? null)) {
            return $error;
        }

        if ($type !== 'code' && $type !== 'html') {
            $content = $this->normalizeBlockUrls($content);
        }

        if ($error = $this->validateColourContrast(
            $parameters['background_color'] ?? null,
            $parameters['text_color'] ?? null,
            $parameters['background_image'] ?? null,
        )) {
            return $error;
        }

        $block = PageBlock::create([
            'page_row_id'  => $row->id,
            'type'         => $type,
            'content'      => $content,
            'settings'     => $parameters['settings'] ?? null,
            'column_index' => $parameters['column_index'] ?? 0,
            'column_width' => $parameters['column_width'] ?? 12,
            'order_column' => $parameters['order'] ?? ((int) $row->blocks()->max('order_column') + 1),
            // Presentation columns — a hero/section background image lives here,
            // not in `settings`.
            'background_image' => $parameters['background_image'] ?? null,
            'background_color' => $parameters['background_color'] ?? null,
            'text_color'       => $parameters['text_color'] ?? null,
            'text_alignment'   => $parameters['text_alignment'] ?? null,
            'padding'          => $parameters['padding'] ?? null,
        ]);
        Page::find($row->page_id)?->touch();

        if ($actionLog) {
            $actionLog->update(['previous_state' => ['created_block_id' => $block->id]]);
        }

        return array_merge(
            ['success' => true, 'block_id' => $block->id, 'row_id' => $row->id],
            $this->describeRenderedBlock($block->fresh()),
        );
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state) || empty($state['created_block_id'])) {
            throw new \RuntimeException('No previous state to undo.');
        }
        $block = PageBlock::find($state['created_block_id']);
        if ($block) {
            $pageId = optional(PageRow::find($block->page_row_id))->page_id;
            $block->delete();
            if ($pageId) {
                Page::find($pageId)?->touch();
            }
        }
    }
}
