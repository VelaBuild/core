<?php
namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;

class GetPageInfoTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $page = Page::find($parameters['page_id']);

        if (!$page) {
            return ['error' => 'Page not found'];
        }

        return [
            'success' => true,
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'status' => $page->status,
                'locale' => $page->locale,
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
                'created_at' => $page->created_at,
                'updated_at' => $page->updated_at,
            ],
        ];
    }
}
