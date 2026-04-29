<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;

class GetPageBlocksTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $page = $this->resolvePage($parameters);
        if (!$page) {
            return ['error' => 'Page not found. Pass page_id or page_slug.'];
        }

        $page->load('rows.blocks');

        $rows = $page->rows->sortBy('order_column')->values()->map(function ($row) {
            return [
                'id'               => $row->id,
                'name'             => $row->name,
                'css_class'        => $row->css_class,
                'background_color' => $row->background_color,
                'background_image' => $row->background_image,
                'text_color'       => $row->text_color,
                'text_alignment'   => $row->text_alignment,
                'padding'          => $row->padding,
                'width'            => $row->width,
                'order'            => $row->order_column,
                'blocks'           => $row->blocks->sortBy('order_column')->values()->map(function ($block) {
                    return [
                        'id'           => $block->id,
                        'type'         => $block->type,
                        'column_index' => $block->column_index,
                        'column_width' => $block->column_width,
                        'order'        => $block->order_column,
                        'content'      => $block->content,
                        'settings'     => $block->settings,
                    ];
                })->toArray(),
            ];
        })->toArray();

        return [
            'success' => true,
            'page'    => [
                'id'     => $page->id,
                'title'  => $page->title,
                'slug'   => $page->slug,
                'locale' => $page->locale,
                'status' => $page->status,
            ],
            'rows'    => $rows,
        ];
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
