<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;

class DeleteRowTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $rowId = $parameters['row_id'] ?? null;
        if (!$rowId) {
            return ['error' => 'row_id is required (get it from get_page_blocks).'];
        }
        $row = PageRow::with('blocks')->find($rowId);
        if (!$row) {
            return ['error' => "Row {$rowId} not found."];
        }

        // Snapshot the row + its blocks so the delete is undoable.
        if ($actionLog) {
            $actionLog->update(['previous_state' => [
                'row'    => $row->getAttributes(),
                'blocks' => $row->blocks->map->getAttributes()->all(),
            ]]);
        }

        $pageId = $row->page_id;
        $row->blocks()->delete();
        $row->delete();
        Page::find($pageId)?->touch();

        return ['success' => true, 'deleted_row_id' => $rowId];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state) || empty($state['row'])) {
            throw new \RuntimeException('No previous state to undo.');
        }
        $row = new PageRow();
        $row->forceFill($state['row']);
        $row->save();
        foreach ($state['blocks'] ?? [] as $blockAttrs) {
            $block = new PageBlock();
            $block->forceFill($blockAttrs);
            $block->save();
        }
        Page::find($row->page_id)?->touch();
    }
}
