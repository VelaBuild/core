<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\DesignSystem;

/**
 * Read a single file from /designsystem. Text files are returned inline;
 * binary files (images, fonts, pdf) return a metadata stub so the AI can
 * still reason about them without burning context on base64.
 */
class DesignSystemReadFileTool extends BaseTool
{
    private const TEXT_EXTS = ['md', 'html', 'htm', 'txt', 'json', 'css', 'svg'];
    private const MAX_TEXT_BYTES = 200_000; // ~200 KB

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $name = (string) ($parameters['name'] ?? '');
        if ($name === '') {
            return ['error' => 'name is required'];
        }

        $ds = app(DesignSystem::class);
        try {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, self::TEXT_EXTS, true)) {
                $contents = $ds->read($name);
                if (strlen($contents) > self::MAX_TEXT_BYTES) {
                    return [
                        'name'     => $name,
                        'type'     => $ext,
                        'bytes'    => strlen($contents),
                        'error'    => 'file exceeds ' . self::MAX_TEXT_BYTES . ' bytes — truncate manually if needed',
                        'preview'  => substr($contents, 0, self::MAX_TEXT_BYTES),
                    ];
                }
                return [
                    'name'     => $name,
                    'type'     => $ext,
                    'bytes'    => strlen($contents),
                    'contents' => $contents,
                ];
            }

            // Binary — return metadata only.
            return [
                'name'  => $name,
                'type'  => $ext,
                'bytes' => filesize($ds->path($name)) ?: 0,
                'mime'  => $ds->mime($name),
                'url'   => route('vela.admin.settings.design-system.download', $name),
                'note'  => 'Binary file — reference by name or URL. Not returned inline.',
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
