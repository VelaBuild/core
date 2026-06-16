<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;

class UpdateBlockTool extends BaseTool
{
    /** Block columns the AI may set (besides moving via row_id). */
    private const FIELDS = ['content', 'settings', 'column_index', 'column_width'];

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $blockId = $parameters['block_id'] ?? null;
        if (!$blockId) {
            return ['error' => 'block_id is required (get it from get_page_blocks).'];
        }
        $block = PageBlock::find($blockId);
        if (!$block) {
            return ['error' => "Block {$blockId} not found."];
        }

        $updates = [];
        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $parameters)) {
                $updates[$f] = $parameters[$f];
            }
        }
        if (array_key_exists('order', $parameters)) {
            $updates['order_column'] = $parameters['order'];
        }
        // Move to another row.
        if (!empty($parameters['row_id']) && $parameters['row_id'] != $block->page_row_id) {
            if (!PageRow::whereKey($parameters['row_id'])->exists()) {
                return ['error' => "Target row {$parameters['row_id']} not found."];
            }
            $updates['page_row_id'] = $parameters['row_id'];
        }
        if (empty($updates)) {
            return ['error' => 'Nothing to update. Pass content, settings, column_index, column_width, order, or row_id (to move).'];
        }

        if ($actionLog) {
            $actionLog->update(['previous_state' => [
                'block_id' => $block->id,
                'before'   => $block->only(['content', 'settings', 'column_index', 'column_width', 'order_column', 'page_row_id']),
            ]]);
        }

        $block->update($updates);
        $pageId = optional(PageRow::find($block->page_row_id))->page_id;
        if ($pageId) {
            Page::find($pageId)?->touch();
        }

        return ['success' => true, 'block_id' => $block->id, 'changed' => array_keys($updates)];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state) || empty($state['block_id'])) {
            throw new \RuntimeException('No previous state to undo.');
        }
        $block = PageBlock::find($state['block_id']);
        if ($block) {
            $block->update($state['before'] ?? []);
            $pageId = optional(PageRow::find($block->page_row_id))->page_id;
            if ($pageId) {
                Page::find($pageId)?->touch();
            }
        }
    }
}
