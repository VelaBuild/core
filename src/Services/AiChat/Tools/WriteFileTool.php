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

        // resources/views/ is writable, and a theme Vela wrote for a design
        // lives inside it. Those views are checked when write_theme_file puts
        // them there; a general file writer must not be the way around that,
        // or the guard just moves the damage to whichever tool has none.
        if (is_file($fullPath) && ($error = $this->themeGuard($fullPath, (string) $content))) {
            return ['error' => $error];
        }

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

    /**
     * Why this write must not go through, if it must not.
     *
     * Returns null for anything that is not a view of a theme Vela authored,
     * which is every ordinary write.
     */
    private function themeGuard(string $fullPath, string $content): ?string
    {
        $themes = dirname(resource_path('views/templates/x'));

        if (!str_starts_with($fullPath, $themes . '/')) {
            return null;
        }

        $theme = explode('/', substr($fullPath, strlen($themes) + 1))[0] ?? '';
        $author = app(\VelaBuild\Core\Services\ThemeAuthor::class);

        if ($theme === '' || !$author->exists($theme)) {
            return null;
        }

        try {
            $author->guardView($fullPath, $content, (string) file_get_contents($fullPath));
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
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
