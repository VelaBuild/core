<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;

class AddRowTool extends BaseTool
{
    /** Row columns the AI may set. */
    private const FIELDS = [
        'name', 'css_class', 'background_color', 'background_image',
        'text_color', 'text_alignment', 'padding', 'width',
    ];

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $page = $this->resolvePage($parameters);
        if (!$page) {
            return ['error' => 'Page not found. Pass page_id or page_slug.'];
        }

        $attributes = ['page_id' => $page->id];
        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $parameters) && $parameters[$f] !== null) {
                $attributes[$f] = $parameters[$f];
            }
        }
        $attributes['width'] = in_array($attributes['width'] ?? null, ['full', 'contained'], true)
            ? $attributes['width'] : 'contained';
        // Default to appending after the last row.
        $attributes['order_column'] = $parameters['order']
            ?? ((int) $page->rows()->max('order_column') + 1);

        if ($error = $this->validateColourContrast(
            $attributes['background_color'] ?? null,
            $attributes['text_color'] ?? null,
            $attributes['background_image'] ?? null,
        )) {
            return $error;
        }

        $row = PageRow::create($attributes);
        $page->touch(); // fire PageObserver → static cache regen

        if ($actionLog) {
            $actionLog->update(['previous_state' => ['created_row_id' => $row->id]]);
        }

        return ['success' => true, 'row_id' => $row->id, 'page_id' => $page->id];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state) || empty($state['created_row_id'])) {
            throw new \RuntimeException('No previous state to undo.');
        }
        $row = PageRow::find($state['created_row_id']);
        if ($row) {
            $pageId = $row->page_id;
            $row->blocks()->delete();
            $row->delete();
            Page::find($pageId)?->touch();
        }
    }

    private function resolvePage(array $params): ?Page
    {
        if (!empty($params['page_id'])) {
            return Page::find($params['page_id']);
        }
        if (!empty($params['page_slug'])) {
            $locale = $params['locale'] ?? config('vela.primary_language', 'en');
            return Page::where('slug', $params['page_slug'])->where('locale', $locale)->first()
                ?? Page::where('slug', $params['page_slug'])->first();
        }
        return null;
    }
}
