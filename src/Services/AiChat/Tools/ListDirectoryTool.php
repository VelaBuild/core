<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

class ListDirectoryTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $path = $parameters['path'] ?? '.';
        $recursive = $parameters['recursive'] ?? false;
        $pattern = $parameters['pattern'] ?? null;

        $base = base_path();
        $fullPath = realpath($base . '/' . ltrim($path, '/'));

        if (!$fullPath || !str_starts_with($fullPath, $base)) {
            return ['error' => 'Invalid path'];
        }

        if (!is_dir($fullPath)) {
            return ['error' => "Not a directory: {$path}"];
        }

        if ($recursive) {
            $cmd = "find " . escapeshellarg($fullPath)
                . " -not -path '*/node_modules/*' -not -path '*/.git/*'"
                . " -maxdepth 4 -type f";
            if ($pattern) {
                $cmd .= " -name " . escapeshellarg($pattern);
            }
            $cmd .= " 2>/dev/null | head -200";

            $output = shell_exec($cmd) ?? '';
            $files = array_filter(explode("\n", trim($output)));
            $entries = array_map(fn($f) => str_replace($base . '/', '', $f), $files);
        } else {
            $entries = [];
            $items = scandir($fullPath);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                if ($pattern && !fnmatch($pattern, $item)) continue;

                $full = $fullPath . '/' . $item;
                $type = is_dir($full) ? 'dir' : 'file';
                $size = is_file($full) ? filesize($full) : null;
                $entries[] = [
                    'name' => $item,
                    'type' => $type,
                    'size' => $size,
                ];
            }
        }

        return ['path' => $path, 'entries' => $entries, 'count' => count($entries)];
    }
}
