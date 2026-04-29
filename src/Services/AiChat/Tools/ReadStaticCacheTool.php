<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

class ReadStaticCacheTool extends BaseTool
{
    private const MAX_BYTES = 512 * 1024;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $relPath = (string) ($parameters['path'] ?? '');
        if ($relPath === '') {
            return ['error' => 'path parameter is required (e.g. "home/index.html" or "posts/my-slug/config.json")'];
        }

        $base = realpath(config('vela.static.path', resource_path('static')));
        if ($base === false) {
            return ['error' => 'Static cache directory does not exist'];
        }

        // Resolve relative path under the base. realpath() canonicalizes and
        // resolves symlinks, so the prefix check below blocks `../` escapes.
        $candidate = $base . DIRECTORY_SEPARATOR . ltrim($relPath, '/\\');
        $full = realpath($candidate);

        if ($full === false || !is_file($full)) {
            return ['error' => "File not found: {$relPath}"];
        }

        if (strncmp($full, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
            return ['error' => 'Access denied: path is outside the static cache directory'];
        }

        $size = filesize($full) ?: 0;
        $content = file_get_contents($full, false, null, 0, self::MAX_BYTES);
        if ($content === false) {
            return ['error' => 'Failed to read file'];
        }

        return [
            'success'   => true,
            'path'      => $relPath,
            'size'      => $size,
            'truncated' => $size > self::MAX_BYTES,
            'content'   => $content,
        ];
    }
}
