<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\SiteContext;

class EditTemplateFileTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $file = $parameters['file'] ?? null;
        $changes = $parameters['changes'] ?? null;

        if (!$file || !$changes) {
            return ['error' => 'file and changes parameters are required'];
        }

        // 1. Resolve active template path
        $activeTemplate = config('vela.template.active', 'default');
        $vela = app(\VelaBuild\Core\Vela::class);
        $templates = $vela->templates()->all();

        if (!isset($templates[$activeTemplate])) {
            return ['error' => "Active template '{$activeTemplate}' not found"];
        }

        $templatePath = $templates[$activeTemplate]['path'];

        // 2. Path traversal prevention
        $resolvedTemplatePath = realpath($templatePath);
        $fullPath = realpath($templatePath . '/' . $file);

        if ($fullPath === false || strpos($fullPath, $resolvedTemplatePath) !== 0) {
            return ['error' => 'Access denied: path outside template directory'];
        }

        if (!file_exists($fullPath)) {
            return ['error' => "File not found: {$file}"];
        }

        // 3. Read current file content
        $currentContent = file_get_contents($fullPath);
        if ($currentContent === false) {
            return ['error' => 'Failed to read file'];
        }

        // 4. Create backup
        $backupDir = storage_path('app/template-backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backupPath = $backupDir . '/' . basename($file) . '.' . now()->format('Y-m-d-H-i-s') . '.backup';
        if (!copy($fullPath, $backupPath)) {
            return ['error' => 'Failed to create backup'];
        }

        // 5. Store backup path in action log
        if ($actionLog) {
            $actionLog->update([
                'previous_state' => [
                    'backup_path'   => $backupPath,
                    'original_path' => $fullPath,
                ],
            ]);
        }

        // 6. Generate edited content via AI
        $aiManager = app(AiProviderManager::class);
        $siteContext = app(SiteContext::class);

        $editPrompt = "You are editing a Blade template file for {$siteContext->getDescription()}.\n\n"
            . "Current file content:\n```\n{$currentContent}\n```\n\n"
            . "User request: {$changes}\n\n"
            . "Return ONLY the complete modified file content. Do not wrap in code blocks. "
            . "Do NOT add @php directives, raw PHP code, system(), exec(), or shell_exec() calls. "
            . "Only modify HTML, CSS classes, Blade directives (@if, @foreach, @include, etc.), and template structure.";

        $textProvider = $aiManager->resolveTextProvider();
        $newContent = $textProvider->generateText($editPrompt, 4000, 0.3);

        if (!$newContent) {
            // Remove backup since we didn't change anything
            if (file_exists($backupPath)) {
                unlink($backupPath);
            }
            return ['error' => 'AI failed to generate template edit'];
        }

        // 7. Clean markdown code blocks from AI output
        $newContent = preg_replace('/^```(?:blade|html|php)?\s*\n?/', '', $newContent);
        $newContent = preg_replace('/\n?\s*```$/', '', $newContent);

        // 8. Security scan
        $dangerousPatterns = [
            '@php', '{!!', 'eval(', 'system(', 'exec(', 'shell_exec(', 'passthru(', 'proc_open(', 'popen(',
        ];
        foreach ($dangerousPatterns as $pattern) {
            if (stripos($newContent, $pattern) !== false) {
                if (file_exists($backupPath)) {
                    unlink($backupPath);
                }
                return ['error' => "AI output contains potentially dangerous code: {$pattern}. Aborting."];
            }
        }

        // 9. Blade compilation check + 10. Atomic write
        $tmpPath = $fullPath . '.tmp';

        try {
            $compiler = app('blade.compiler');
            $compiler->compileString($newContent);

            file_put_contents($tmpPath, $newContent);
            rename($tmpPath, $fullPath);
        } catch (\Exception $e) {
            // 11. Rollback from backup on compilation failure
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            copy($backupPath, $fullPath);
            return ['error' => "Blade compilation failed. Changes rolled back. Error: {$e->getMessage()}"];
        }

        // 12. Cleanup old backups
        $this->cleanupBackups($backupDir, basename($file), (int) config('vela.ai.chat.backup_retention', 5));

        // 13. Return success
        return [
            'success' => true,
            'file'    => $file,
            'message' => 'Template file updated',
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;

        if (!$state || !isset($state['backup_path']) || !isset($state['original_path'])) {
            throw new \RuntimeException('No previous state to restore.');
        }

        if (!file_exists($state['backup_path'])) {
            throw new \RuntimeException("Backup file not found: {$state['backup_path']}");
        }

        if (!copy($state['backup_path'], $state['original_path'])) {
            throw new \RuntimeException("Failed to restore file from backup.");
        }
    }

    private function cleanupBackups(string $dir, string $basename, int $keep): void
    {
        $pattern = $dir . '/' . $basename . '.*.backup';
        $backups = glob($pattern);
        if ($backups === false) {
            return;
        }
        sort($backups); // oldest first
        while (count($backups) > $keep) {
            unlink(array_shift($backups));
        }
    }
}
