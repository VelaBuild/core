<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use Illuminate\Support\Facades\Artisan;

class SchedulerTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $action = $parameters['action'] ?? 'list';

        return match ($action) {
            'list' => $this->listSchedule(),
            'run' => $this->runSchedule($parameters),
            'test' => $this->testCommand($parameters),
            default => ['error' => "Unknown action: {$action}. Available: list, run, test"],
        };
    }

    private function listSchedule(): array
    {
        $output = shell_exec('cd ' . escapeshellarg(base_path()) . ' && php artisan schedule:list 2>&1') ?? '';
        return ['schedule' => $output];
    }

    private function runSchedule(array $params): array
    {
        if (!($params['confirm'] ?? false)) {
            return [
                'requires_confirmation' => true,
                'message' => 'Running the scheduler will execute all due tasks. Call again with confirm: true.',
            ];
        }

        $output = shell_exec('cd ' . escapeshellarg(base_path()) . ' && php artisan schedule:run 2>&1') ?? '';
        return ['output' => $output];
    }

    private function testCommand(array $params): array
    {
        $command = $params['command'] ?? '';
        if (!$command) return ['error' => 'command is required'];

        if (!($params['confirm'] ?? false)) {
            return [
                'requires_confirmation' => true,
                'command' => $command,
                'message' => "This will run artisan command: {$command}. Call again with confirm: true.",
            ];
        }

        $output = shell_exec('cd ' . escapeshellarg(base_path()) . ' && php artisan ' . escapeshellarg($command) . ' 2>&1') ?? '';
        return ['command' => $command, 'output' => $output];
    }
}
