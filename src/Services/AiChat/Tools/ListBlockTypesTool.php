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
            $entry = [
                'type'             => $name,
                'label'            => $def['label'] ?? $name,
                'default_content'  => $def['defaults']['content'] ?? null,
                'default_settings' => $def['defaults']['settings'] ?? null,
            ];

            // List-style blocks default to an empty array, which says nothing
            // about what one entry holds. Where the block declares an example,
            // pass it through — it is the only description of that shape.
            if (!empty($def['content_example'])) {
                $entry['content_example'] = $def['content_example'];
            }

            $types[] = $entry;
        }

        return [
            'success' => true,
            'types'   => $types,
        ];
    }
}
