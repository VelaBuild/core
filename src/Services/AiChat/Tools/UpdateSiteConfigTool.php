<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\VelaConfig;

class UpdateSiteConfigTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $key = $parameters['key'] ?? null;
        $value = $parameters['value'] ?? null;

        if (!$key) {
            return ['error' => 'Key parameter is required'];
        }

        $current = VelaConfig::where('key', $key)->first();

        if ($actionLog) {
            $actionLog->update([
                'previous_state' => [
                    'key' => $key,
                    'value' => $current?->value,
                    'existed' => $current !== null,
                ],
            ]);
        }

        VelaConfig::updateOrCreate(['key' => $key], ['value' => $value]);

        return [
            'success' => true,
            'key' => $key,
            'value' => $value,
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state) {
            throw new \RuntimeException('No previous state to restore.');
        }

        $key = $state['key'];

        if ($state['existed']) {
            VelaConfig::updateOrCreate(['key' => $key], ['value' => $state['value']]);
        } else {
            VelaConfig::where('key', $key)->delete();
        }
    }
}
