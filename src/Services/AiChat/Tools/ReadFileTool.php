<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

class ReadFileTool extends BaseTool
{
    private const MAX_SIZE = 100_000;

    private const BLOCKED_PATTERNS = [
        '/\.env/',
        '/vendor\//',
        '/node_modules\//',
        '/\.git\//',
        '/storage\/logs\//',
        '/storage\/framework\/sessions\//',
    ];

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $path = $parameters['path'] ?? '';
        if (!$path) {
            return ['error' => 'path is required'];
        }

        $resolved = $this->resolvePath($path);
        if (!$resolved) {
            return ['error' => "Cannot resolve path: {$path}"];
        }

        if ($this->isBlocked($resolved)) {
            return ['error' => "Access denied: {$path}"];
        }

        if (!is_file($resolved)) {
            return ['error' => "File not found: {$path}"];
        }

        if (filesize($resolved) > self::MAX_SIZE) {
            $offset = $parameters['offset'] ?? 0;
            $limit = min($parameters['limit'] ?? 2000, 5000);
            $lines = file($resolved);
            $slice = array_slice($lines, $offset, $limit);
            return [
                'content' => implode('', $slice),
                'total_lines' => count($lines),
                'showing' => $offset . '-' . ($offset + count($slice)),
                'truncated' => true,
            ];
        }

        return ['content' => file_get_contents($resolved), 'path' => $path];
    }

    private function resolvePath(string $path): ?string
    {
        $base = base_path();
        $full = realpath($base . '/' . ltrim($path, '/'));
        if (!$full || !str_starts_with($full, $base)) {
            return null;
        }
        return $full;
    }

    private function isBlocked(string $fullPath): bool
    {
        $relative = str_replace(base_path() . '/', '', $fullPath);
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $relative)) {
                return true;
            }
        }
        return false;
    }
}
