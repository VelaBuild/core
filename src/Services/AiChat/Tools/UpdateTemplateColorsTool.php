<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\VelaConfig;

class UpdateTemplateColorsTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $colors = $parameters['colors'] ?? [];

        if (empty($colors)) {
            return ['error' => 'Colors parameter is required'];
        }

        $previousState = [];
        foreach ($colors as $var => $value) {
            $configKey = "css_{$var}";
            $current = VelaConfig::where('key', $configKey)->first();
            $previousState[$var] = [
                'key' => $configKey,
                'value' => $current?->value,
                'existed' => $current !== null,
            ];
        }

        if ($actionLog) {
            $actionLog->update(['previous_state' => $previousState]);
        }

        foreach ($colors as $var => $value) {
            $configKey = "css_{$var}";
            VelaConfig::updateOrCreate(['key' => $configKey], ['value' => $value]);
        }

        return [
            'success' => true,
            'updated' => array_keys($colors),
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state) {
            throw new \RuntimeException('No previous state to restore.');
        }

        foreach ($state as $var => $prev) {
            if ($prev['existed']) {
                VelaConfig::updateOrCreate(['key' => $prev['key']], ['value' => $prev['value']]);
            } else {
                VelaConfig::where('key', $prev['key'])->delete();
            }
        }
    }
}
