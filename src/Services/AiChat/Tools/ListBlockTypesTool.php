<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

class ListBlockTypesTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $blocks = app(\VelaBuild\Core\Vela::class)->blocks()->all();

        $types = [];
        foreach ($blocks as $name => $def) {
            $types[] = [
                'type'             => $name,
                'label'            => $def['label'] ?? $name,
                'default_content'  => $def['defaults']['content'] ?? null,
                'default_settings' => $def['defaults']['settings'] ?? null,
            ];
        }

        return [
            'success' => true,
            'types'   => $types,
        ];
    }
}
