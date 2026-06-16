<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;

class UpdateRowTool extends BaseTool
{
    private const FIELDS = [
        'name', 'css_class', 'background_color', 'background_image',
        'text_color', 'text_alignment', 'padding', 'width', 'order_column',
    ];

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $rowId = $parameters['row_id'] ?? null;
        if (!$rowId) {
            return ['error' => 'row_id is required (get it from get_page_blocks).'];
        }
        $row = PageRow::find($rowId);
        if (!$row) {
            return ['error' => "Row {$rowId} not found."];
        }

        // `order` is the friendly name for order_column.
        if (array_key_exists('order', $parameters)) {
            $parameters['order_column'] = $parameters['order'];
        }

        $updates = [];
        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $parameters) && $parameters[$f] !== null) {
                $updates[$f] = $parameters[$f];
            }
        }
        if (isset($updates['width']) && !in_array($updates['width'], ['full', 'contained'], true)) {
            unset($updates['width']);
        }
        if (empty($updates)) {
            return ['error' => 'No fields to update. Pass at least one row property.'];
        }

        if ($actionLog) {
            $actionLog->update(['previous_state' => [
                'row_id' => $row->id,
                'before' => $row->only(self::FIELDS),
            ]]);
        }

        $row->update($updates);
        Page::find($row->page_id)?->touch();

        return ['success' => true, 'row_id' => $row->id, 'changed' => array_keys($updates)];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state) || empty($state['row_id'])) {
            throw new \RuntimeException('No previous state to undo.');
        }
        $row = PageRow::find($state['row_id']);
        if ($row) {
            $row->update($state['before'] ?? []);
            Page::find($row->page_id)?->touch();
        }
    }
}
