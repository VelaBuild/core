<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

class GetErrorLogTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $lines = $parameters['lines'] ?? 50;
        $lines = min(max($lines, 10), 200);
        $filter = $parameters['filter'] ?? null;

        $logFile = storage_path('logs/laravel.log');
        if (!is_file($logFile)) {
            return ['content' => '', 'message' => 'No log file found'];
        }

        $cmd = "tail -n {$lines} " . escapeshellarg($logFile);
        if ($filter) {
            $cmd .= " | grep -i " . escapeshellarg($filter);
        }

        $output = shell_exec($cmd) ?? '';

        return [
            'content' => mb_substr($output, 0, 30_000),
            'lines' => $lines,
            'file' => 'storage/logs/laravel.log',
        ];
    }
}
