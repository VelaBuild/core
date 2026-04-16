<?php
namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\VelaConfig;

class GetSiteConfigTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        if (!empty($parameters['key'])) {
            $value = VelaConfig::where('key', $parameters['key'])->value('value');
            return [
                'success' => true,
                'config' => [$parameters['key'] => $value],
            ];
        }

        $config = VelaConfig::all()->pluck('value', 'key')->toArray();

        return [
            'success' => true,
            'config' => $config,
        ];
    }
}
