<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\DesignSystem;

/**
 * Read-only: list every file in /designsystem with size + type + timestamp.
 * The AI uses this to decide which files are worth reading before making
 * design or content decisions — no file bodies in the response.
 */
class DesignSystemListTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $ds = app(DesignSystem::class);
        return [
            'total_files' => count($ds->files()),
            'total_bytes' => $ds->totalBytes(),
            'files'       => $ds->files(),
            'hint'        => 'Call design_system_read_file with a name from this list to fetch content. '
                           . 'For colours/fonts use design_system_palette / design_system_fonts directly.',
        ];
    }
}
