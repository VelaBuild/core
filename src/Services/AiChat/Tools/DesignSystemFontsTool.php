<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\DesignSystem;

/**
 * Read-only: return the configured font families with their source URLs +
 * weights. The AI uses this when generating CSS or recommending type choices.
 */
class DesignSystemFontsTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        return app(DesignSystem::class)->fonts();
    }
}
