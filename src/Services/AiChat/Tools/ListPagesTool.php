<?php
namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;

class ListPagesTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $pages = Page::select('id', 'title', 'slug', 'status')
            ->orderBy('updated_at', 'desc')
            ->take(50)
            ->get()
            ->toArray();

        return ['success' => true, 'pages' => $pages];
    }
}
