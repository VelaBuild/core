<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Category;

class CreateCategoryTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $name = $parameters['name'] ?? null;

        if (!$name) {
            return ['error' => 'Name parameter is required'];
        }

        $category = Category::create(['name' => $name]);

        if ($actionLog) {
            $actionLog->update([
                'previous_state' => ['created_id' => $category->id],
            ]);
        }

        return [
            'success' => true,
            'category' => [
                'id'   => $category->id,
                'name' => $category->name,
            ],
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state || !isset($state['created_id'])) {
            throw new \RuntimeException('No previous state to restore.');
        }

        Category::find($state['created_id'])?->delete();
    }
}
