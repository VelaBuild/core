<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

class DiffFileTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $action = $parameters['action'] ?? 'file';

        return match ($action) {
            'file' => $this->diffFile($parameters),
            'git' => $this->gitDiff($parameters),
            'strings' => $this->diffStrings($parameters),
            default => ['error' => "Unknown action: {$action}. Available: file, git, strings"],
        };
    }

    private function diffFile(array $params): array
    {
        $path = $params['path'] ?? '';
        if (!$path) return ['error' => 'path is required'];

        $base = base_path();
        $full = realpath($base . '/' . ltrim($path, '/'));
        if (!$full || !str_starts_with($full, $base)) {
            return ['error' => 'Invalid path'];
        }

        $cmd = 'git diff -- ' . escapeshellarg($path) . ' 2>/dev/null';
        $diff = shell_exec("cd " . escapeshellarg($base) . " && {$cmd}") ?? '';

        if (!$diff) {
            $cmd = 'git diff HEAD -- ' . escapeshellarg($path) . ' 2>/dev/null';
            $diff = shell_exec("cd " . escapeshellarg($base) . " && {$cmd}") ?? '';
        }

        return [
            'path' => $path,
            'diff' => $diff ?: 'No changes detected',
            'has_changes' => $diff !== '',
        ];
    }

    private function gitDiff(array $params): array
    {
        $base = base_path();
        $scope = $params['scope'] ?? 'staged';

        $cmd = match ($scope) {
            'staged' => 'git diff --cached --stat 2>/dev/null',
            'unstaged' => 'git diff --stat 2>/dev/null',
            'all' => 'git diff HEAD --stat 2>/dev/null',
            'log' => 'git log --oneline -20 2>/dev/null',
            'status' => 'git status --short 2>/dev/null',
            default => 'git status --short 2>/dev/null',
        };

        $output = shell_exec("cd " . escapeshellarg($base) . " && {$cmd}") ?? '';

        return ['scope' => $scope, 'output' => $output];
    }

    private function diffStrings(array $params): array
    {
        $original = $params['original'] ?? '';
        $modified = $params['modified'] ?? '';

        if (!$original && !$modified) {
            return ['error' => 'original and modified are required'];
        }

        $tmpOrig = tempnam(sys_get_temp_dir(), 'diff_orig_');
        $tmpMod = tempnam(sys_get_temp_dir(), 'diff_mod_');
        file_put_contents($tmpOrig, $original);
        file_put_contents($tmpMod, $modified);

        $diff = shell_exec('diff -u ' . escapeshellarg($tmpOrig) . ' ' . escapeshellarg($tmpMod) . ' 2>/dev/null') ?? '';

        unlink($tmpOrig);
        unlink($tmpMod);

        return ['diff' => $diff, 'has_changes' => $diff !== ''];
    }
}
