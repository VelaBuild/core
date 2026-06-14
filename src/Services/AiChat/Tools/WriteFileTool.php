<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

class WriteFileTool extends BaseTool
{
    private const ALLOWED_DIRS = [
        'resources/views/',
        'resources/lang/',
        'resources/css/',
        'resources/js/',
        'public/css/',
        'public/js/',
        'routes/',
        'app/',
        'config/',
        'database/migrations/',
        'database/seeders/',
        'tests/',
    ];

    private const BLOCKED_PATTERNS = [
        '/\.env/',
        '/vendor\//',
        '/node_modules\//',
        '/\.git\//',
        '/composer\.lock/',
        '/storage\/framework\//',
    ];

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $path = $parameters['path'] ?? '';
        $content = $parameters['content'] ?? null;

        if (!$path || $content === null) {
            return ['error' => 'path and content are required'];
        }

        if (!$this->isAllowed($path)) {
            return ['error' => "Write not allowed to: {$path}. Allowed directories: " . implode(', ', self::ALLOWED_DIRS)];
        }

        if ($this->isBlocked($path)) {
            return ['error' => "Access denied: {$path}"];
        }

        $fullPath = base_path(ltrim($path, '/'));

        if ($actionLog && is_file($fullPath)) {
            $actionLog->update(['previous_state' => ['content' => file_get_contents($fullPath)]]);
        }

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, $content);

        return ['success' => true, 'path' => $path, 'bytes' => strlen($content)];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $prev = $actionLog->previous_state;
        $path = $actionLog->parameters['path'] ?? null;
        if (!$path) return;

        $fullPath = base_path(ltrim($path, '/'));
        if (isset($prev['content'])) {
            file_put_contents($fullPath, $prev['content']);
        } elseif (is_file($fullPath)) {
            unlink($fullPath);
        }
    }

    private function isAllowed(string $path): bool
    {
        $path = ltrim($path, '/');
        foreach (self::ALLOWED_DIRS as $dir) {
            if (str_starts_with($path, $dir)) {
                return true;
            }
        }
        return false;
    }

    private function isBlocked(string $path): bool
    {
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }
        return false;
    }
}
