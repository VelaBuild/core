<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\SiteContext;
use VelaBuild\Core\Models\VelaConfig;

class CustomizeTemplate extends Command
{
    protected $signature = 'vela:customize-template
                            {--template= : Template name to customize}
                            {--colors= : JSON object of CSS variable overrides e.g. {"--primary":"#ff0000"}}
                            {--prompt= : AI prompt for template customization}
                            {--file= : Specific template file to edit (relative to template dir)}
                            {--dry-run : Show changes without applying}
                            {--force : Overwrite without backup confirmation}';

    protected $description = 'Customize template via config changes or AI-powered file editing';

    private AiProviderManager $aiManager;

    public function __construct(AiProviderManager $aiManager)
    {
        parent::__construct();
        $this->aiManager = $aiManager;
    }

    public function handle(): int
    {
        $template = $this->option('template') ?? config('vela.template.active', 'default');
        $colors = $this->option('colors');
        $prompt = $this->option('prompt');
        $file = $this->option('file');
        $dryRun = $this->option('dry-run');

        // MODE 1: Config-based customization (CSS variables)
        if ($colors) {
            return $this->applyColorConfig($colors, $dryRun);
        }

        // MODE 2: AI-powered file editing
        if ($prompt) {
            if (!$this->aiManager->hasTextProvider()) {
                $this->error('No AI text provider configured.');
                return 1;
            }
            return $this->applyAiEdit($template, $prompt, $file, $dryRun);
        }

        // Interactive mode
        $mode = $this->choice('Customization mode', ['colors' => 'Update CSS color variables', 'ai' => 'AI-powered template edit'], 'colors');
        if ($mode === 'colors') {
            $colorJson = $this->ask('Enter CSS variables as JSON (e.g. {"--primary":"#ff0000"})');
            return $this->applyColorConfig($colorJson, $dryRun);
        } else {
            $prompt = $this->ask('Describe the changes you want');
            return $this->applyAiEdit($template, $prompt, null, $dryRun);
        }
    }

    private function applyColorConfig(string $colorsJson, bool $dryRun): int
    {
        $colors = json_decode($colorsJson, true);
        if (!$colors) {
            $this->error('Invalid JSON for colors.');
            return 1;
        }

        foreach ($colors as $var => $value) {
            if ($dryRun) {
                $this->line("Would set {$var} = {$value}");
                continue;
            }
            VelaConfig::updateOrCreate(['key' => "css_{$var}"], ['value' => $value]);
            $this->info("Set {$var} = {$value}");
        }
        return 0;
    }

    private function applyAiEdit(string $template, string $prompt, ?string $file, bool $dryRun): int
    {
        // Resolve template path from TemplateRegistry
        $templatePath = $this->resolveTemplatePath($template);
        if (!$templatePath) {
            $this->error("Template '{$template}' not found.");
            return 1;
        }

        // Require --file when using --prompt
        if (!$file) {
            $this->error('Please specify --file when using AI edit mode.');
            return 1;
        }

        $targetFile = $templatePath . '/' . $file;

        if (!file_exists($targetFile)) {
            $this->error("File not found: {$targetFile}");
            return 1;
        }

        $currentContent = file_get_contents($targetFile);

        // Backup before edit (following SetupGraphics atomic write pattern)
        $backupDir = storage_path('app/template-backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        $backupPath = $backupDir . '/' . basename($targetFile) . '.' . now()->format('Y-m-d-H-i-s') . '.backup';

        if (!$dryRun) {
            copy($targetFile, $backupPath);
            $this->line("Backup created: {$backupPath}");
        }

        // Ask AI to edit the file
        $textProvider = $this->aiManager->resolveTextProvider();
        $siteContext = app(SiteContext::class);

        $editPrompt = "You are editing a Blade template file for {$siteContext->getDescription()}.\n\n"
            . "Current file content:\n```\n{$currentContent}\n```\n\n"
            . "User request: {$prompt}\n\n"
            . "Return ONLY the complete modified file content. Do not wrap in code blocks. "
            . "Do NOT add @php directives, raw PHP code, system(), exec(), or shell_exec() calls. "
            . "Only modify HTML, CSS classes, Blade directives (@if, @foreach, @include, etc.), and template structure.";

        $newContent = $textProvider->generateText($editPrompt, 4000, 0.3);
        if (!$newContent) {
            $this->error('AI failed to generate template edit.');
            return 1;
        }

        // Clean markdown code blocks if AI wrapped the output
        $newContent = preg_replace('/^```(?:blade|html|php)?\s*\n?/', '', $newContent);
        $newContent = preg_replace('/\n?\s*```$/', '', $newContent);

        if ($dryRun) {
            $this->info('Dry run - proposed changes:');
            $this->line($newContent);
            return 0;
        }

        // Security: scan for dangerous PHP patterns
        $dangerousPatterns = ['@php', '{!!', 'eval(', 'system(', 'exec(', 'shell_exec(', 'passthru(', 'proc_open(', 'popen('];
        foreach ($dangerousPatterns as $pattern) {
            if (stripos($newContent, $pattern) !== false) {
                $this->error("AI output contains potentially dangerous code: {$pattern}. Aborting.");
                return 1;
            }
        }

        // Atomic write (tmp + rename)
        $tmpPath = $targetFile . '.tmp';
        file_put_contents($tmpPath, $newContent);

        // Blade compilation check
        try {
            $compiler = app('blade.compiler');
            $compiler->compileString($newContent);
            rename($tmpPath, $targetFile);
            $this->info("Template file updated: {$targetFile}");

            // Cleanup old backups (keep only last N)
            $this->cleanupBackups($backupDir, basename($targetFile), config('vela.ai.chat.backup_retention', 5));
        } catch (\Exception $e) {
            // Rollback
            unlink($tmpPath);
            copy($backupPath, $targetFile);
            $this->error("Blade compilation failed. Changes rolled back. Error: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }

    private function resolveTemplatePath(string $template): ?string
    {
        $vela = app(\VelaBuild\Core\Vela::class);
        $templates = $vela->templates()->all();
        if (isset($templates[$template])) {
            return $templates[$template]['path'];
        }
        return null;
    }

    private function cleanupBackups(string $dir, string $basename, int $keep): void
    {
        $pattern = $dir . '/' . $basename . '.*.backup';
        $backups = glob($pattern);
        sort($backups); // oldest first
        while (count($backups) > $keep) {
            unlink(array_shift($backups));
        }
    }
}
