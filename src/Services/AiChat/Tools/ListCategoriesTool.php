<?php
namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Category;

class ListCategoriesTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $categories = Category::select('id', 'name')
            ->orderBy('name')
            ->get()
            ->toArray();

        return ['success' => true, 'categories' => $categories];
    }
}
