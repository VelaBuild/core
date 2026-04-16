<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Vela;

class ListTemplatesTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $registry = app(Vela::class)->templates();
        $templates = [];

        $activeTemplate = VelaConfig::where('key', 'active_template')->value('value')
            ?: config('vela.template.active', 'default');

        foreach ($registry->all() as $name => $config) {
            $templates[] = [
                'name' => $name,
                'label' => $config['label'],
                'description' => $config['description'],
                'category' => $config['category'],
                'active' => ($name === $activeTemplate),
            ];
        }

        return [
            'success' => true,
            'templates' => $templates,
            'active_template' => $activeTemplate,
        ];
    }
}
