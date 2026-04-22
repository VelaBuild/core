<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\DesignSystem;

/**
 * Read-only: return the current colour palette so the AI can reference
 * brand colours by name/slug when generating content or CSS.
 */
class DesignSystemPaletteTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        return app(DesignSystem::class)->palette();
    }
}
