<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\ThemeAuthor;

class GetThemeContractTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        return [
            'success' => true,
            'contract' => app(ThemeAuthor::class)->contract(),
        ];
    }
}
