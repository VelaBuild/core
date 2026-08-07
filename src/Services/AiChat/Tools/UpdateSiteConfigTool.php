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

        $result = [
            'success' => true,
            'key' => $key,
            'value' => $value,
        ];

        // Writing a key nothing has ever stored usually means the key was
        // invented: it is saved, but no code reads it, so the site does not
        // change. Say so rather than let it be reported to the user as done.
        if ($current === null) {
            $result['warning'] = "'{$key}' did not exist before this write, so nothing in the site may read it. "
                . 'Verify the change actually took effect before telling the user it is done — if the setting has a '
                . 'dedicated mechanism (menus live in the vela_menus table, not in config, for example), use that instead.';
            $result['existing_keys'] = VelaConfig::orderBy('key')->pluck('key')->all();
        }

        return $result;
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
