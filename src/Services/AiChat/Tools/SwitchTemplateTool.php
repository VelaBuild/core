<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Vela;

class SwitchTemplateTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $templateName = $parameters['template'] ?? null;
        if (!$templateName) {
            return ['error' => 'template parameter is required'];
        }

        $registry = app(Vela::class)->templates();
        if (!$registry->has($templateName)) {
            $available = array_keys($registry->all());
            return ['error' => "Template '{$templateName}' not found. Available: " . implode(', ', $available)];
        }

        $current = VelaConfig::where('key', 'active_template')->first();
        if ($actionLog) {
            $actionLog->update([
                'previous_state' => [
                    'key' => 'active_template',
                    'value' => $current?->value,
                    'existed' => $current !== null,
                ],
            ]);
        }

        VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => $templateName]);

        $templateInfo = $registry->get($templateName);
        return [
            'success' => true,
            'template' => $templateName,
            'label' => $templateInfo['label'],
            'description' => $templateInfo['description'],
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state) {
            throw new \RuntimeException('No previous state to restore.');
        }
        if ($state['existed']) {
            VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => $state['value']]);
        } else {
            VelaConfig::where('key', 'active_template')->delete();
        }
    }
}
