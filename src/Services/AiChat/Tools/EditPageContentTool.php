<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Models\PageBlock;

class EditPageContentTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $pageId = $parameters['page_id'] ?? null;
        $content = $parameters['content'] ?? null;

        if (!$pageId) {
            return ['error' => 'page_id parameter is required'];
        }
        if ($content === null) {
            return ['error' => 'content parameter is required'];
        }

        $page = Page::find($pageId);
        if (!$page) {
            return ['error' => "Page {$pageId} not found"];
        }

        // Capture current rows/blocks for undo
        if ($actionLog) {
            $previousRows = $page->rows()->with('blocks')->get()->map(function ($row) {
                return [
                    'id'           => $row->id,
                    'name'         => $row->name,
                    'css_class'    => $row->css_class,
                    'order_column' => $row->order_column,
                    'blocks'       => $row->blocks->map(function ($block) {
                        return [
                            'id'           => $block->id,
                            'column_index' => $block->column_index,
                            'column_width' => $block->column_width,
                            'order_column' => $block->order_column,
                            'type'         => $block->type,
                            'content'      => $block->content,
                            'settings'     => $block->settings,
                        ];
                    })->toArray(),
                ];
            })->toArray();

            $actionLog->update([
                'previous_state' => ['page_id' => $pageId, 'rows' => $previousRows],
            ]);
        }

        // Remove existing rows/blocks and replace with a single text block
        foreach ($page->rows as $row) {
            $row->blocks()->delete();
            $row->delete();
        }

        $row = PageRow::create([
            'page_id'      => $page->id,
            'order_column' => 1,
        ]);

        PageBlock::create([
            'page_row_id'  => $row->id,
            'column_index' => 0,
            'column_width' => 12,
            'order_column' => 1,
            'type'         => 'text',
            'content'      => ['body' => $content],
        ]);

        return [
            'success' => true,
            'page_id' => $page->id,
            'message' => 'Page content updated',
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state || !isset($state['page_id'])) {
            throw new \RuntimeException('No previous state to restore.');
        }

        $page = Page::find($state['page_id']);
        if (!$page) {
            throw new \RuntimeException("Page {$state['page_id']} not found for undo.");
        }

        // Remove current rows/blocks
        foreach ($page->rows as $row) {
            $row->blocks()->delete();
            $row->delete();
        }

        // Restore previous rows/blocks
        foreach ($state['rows'] as $rowData) {
            $row = PageRow::create([
                'page_id'      => $page->id,
                'name'         => $rowData['name'],
                'css_class'    => $rowData['css_class'],
                'order_column' => $rowData['order_column'],
            ]);

            foreach ($rowData['blocks'] as $blockData) {
                PageBlock::create([
                    'page_row_id'  => $row->id,
                    'column_index' => $blockData['column_index'],
                    'column_width' => $blockData['column_width'],
                    'order_column' => $blockData['order_column'],
                    'type'         => $blockData['type'],
                    'content'      => $blockData['content'],
                    'settings'     => $blockData['settings'],
                ]);
            }
        }
    }
}
