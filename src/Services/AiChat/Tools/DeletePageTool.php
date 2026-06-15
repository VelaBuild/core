<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Models\PageBlock;

class DeletePageTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $page = $this->resolvePage($parameters);
        if (!$page) {
            return ['error' => 'Page not found. Pass page_id or page_slug (call list_pages to find it).'];
        }

        // The home page is structural — refuse to delete it.
        if ($page->slug === 'home') {
            return ['error' => 'Refusing to delete the home page.'];
        }

        // Snapshot the full page (attributes + rows + blocks) so the delete
        // is undoable.
        if ($actionLog) {
            $actionLog->update([
                'previous_state' => [
                    'attributes' => $page->getAttributes(),
                    'rows'       => $page->rows()->with('blocks')->get()->map(function ($row) {
                        return [
                            'attributes' => $row->getAttributes(),
                            'blocks'     => $row->blocks->map->getAttributes()->all(),
                        ];
                    })->all(),
                ],
            ]);
        }

        $id = $page->id;
        $title = $page->title;
        $slug = $page->slug;

        // Deleting the Page cascades cleanup via PageObserver (menu items +
        // static cache). Remove its rows/blocks explicitly first.
        foreach ($page->rows as $row) {
            $row->blocks()->delete();
            $row->delete();
        }
        $page->delete();

        return [
            'success' => true,
            'deleted' => ['id' => $id, 'title' => $title, 'slug' => $slug],
            'message' => "Deleted page '{$title}' (id={$id}, slug={$slug}).",
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state) || empty($state['attributes'])) {
            throw new \RuntimeException('No previous state to restore.');
        }

        // Recreate the page preserving its original id so references hold.
        $page = new Page();
        $page->forceFill($state['attributes']);
        $page->save();

        foreach ($state['rows'] ?? [] as $rowData) {
            $row = new PageRow();
            $row->forceFill($rowData['attributes']);
            $row->save();

            foreach ($rowData['blocks'] ?? [] as $blockAttrs) {
                $block = new PageBlock();
                $block->forceFill($blockAttrs);
                $block->save();
            }
        }
    }

    private function resolvePage(array $parameters): ?Page
    {
        if (!empty($parameters['page_id'])) {
            return Page::find($parameters['page_id']);
        }
        if (!empty($parameters['page_slug'])) {
            return Page::where('slug', $parameters['page_slug'])->first();
        }
        return null;
    }
}
