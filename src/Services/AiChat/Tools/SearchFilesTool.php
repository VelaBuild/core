<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

class SearchFilesTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $pattern = $parameters['pattern'] ?? '';
        $path = $parameters['path'] ?? '.';
        $type = $parameters['type'] ?? 'grep';

        if (!$pattern) {
            return ['error' => 'pattern is required'];
        }

        $base = base_path();
        $searchPath = realpath($base . '/' . ltrim($path, '/'));
        if (!$searchPath || !str_starts_with($searchPath, $base)) {
            return ['error' => 'Invalid search path'];
        }

        if ($type === 'glob') {
            return $this->globSearch($searchPath, $pattern, $base);
        }

        return $this->grepSearch($searchPath, $pattern, $base, $parameters);
    }

    private function grepSearch(string $searchPath, string $pattern, string $base, array $params): array
    {
        $exclude = '--exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git --exclude-dir=storage';
        $flags = '-rn';
        if ($params['case_insensitive'] ?? false) {
            $flags .= 'i';
        }
        $maxResults = min($params['max_results'] ?? 50, 100);
        $fileFilter = $params['file_pattern'] ?? '';
        $includeFlag = $fileFilter ? "--include='{$fileFilter}'" : '';

        $escaped = escapeshellarg($pattern);
        $cmd = "grep {$flags} {$exclude} {$includeFlag} {$escaped} " . escapeshellarg($searchPath) . " 2>/dev/null | head -n {$maxResults}";

        $output = shell_exec($cmd) ?? '';
        $lines = array_filter(explode("\n", trim($output)));

        $results = [];
        foreach ($lines as $line) {
            $relative = str_replace($base . '/', '', $line);
            $results[] = $relative;
        }

        return [
            'matches' => $results,
            'count' => count($results),
            'truncated' => count($results) >= $maxResults,
        ];
    }

    private function globSearch(string $searchPath, string $pattern, string $base): array
    {
        $cmd = "find " . escapeshellarg($searchPath)
            . " -not -path '*/vendor/*' -not -path '*/node_modules/*' -not -path '*/.git/*'"
            . " -name " . escapeshellarg($pattern)
            . " -type f 2>/dev/null | head -100";

        $output = shell_exec($cmd) ?? '';
        $files = array_filter(explode("\n", trim($output)));

        $results = [];
        foreach ($files as $file) {
            $results[] = str_replace($base . '/', '', $file);
        }

        return ['files' => $results, 'count' => count($results)];
    }
}
