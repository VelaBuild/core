<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;

class DeleteBlockTool extends BaseTool
{
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

        if ($actionLog) {
            $actionLog->update(['previous_state' => ['block' => $block->getAttributes()]]);
        }

        $pageId = optional(PageRow::find($block->page_row_id))->page_id;
        $block->delete();
        if ($pageId) {
            Page::find($pageId)?->touch();
        }

        return ['success' => true, 'deleted_block_id' => $blockId];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state) || empty($state['block'])) {
            throw new \RuntimeException('No previous state to undo.');
        }
        $block = new PageBlock();
        $block->forceFill($state['block']);
        $block->save();
        $pageId = optional(PageRow::find($block->page_row_id))->page_id;
        if ($pageId) {
            Page::find($pageId)?->touch();
        }
    }
}
