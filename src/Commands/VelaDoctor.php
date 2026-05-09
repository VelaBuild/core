<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;

/**
 * Project-health diagnostics — detects misconfigurations and dangerous
 * leftover state in a Vela host app.
 *
 *   php artisan vela:doctor
 *
 * Currently checks:
 *   - Stale forks of admin Blade views under
 *     resources/views/vendor/vela/ that silently override the package
 *     and hide future updates / security fixes.
 *
 * Add new checks by extending the $checks array in handle().
 */
class VelaDoctor extends Command
{
    protected $signature = 'vela:doctor {--fix : Attempt to remove safe-to-delete leftovers (admin view forks)}';
    protected $description = 'Diagnose common configuration issues in a Vela host app.';

    private int $issues = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('vela:doctor — host app health check');

        $this->checkStaleAdminViewForks();
        // Future: checkStalePartialForks(), checkOrphanedMenus(), checkMissingMigrations()…

        $this->newLine();
        if ($this->issues === 0) {
            $this->components->info('No issues found.');
            return 0;
        }
        $this->components->warn("{$this->issues} issue(s) detected. " . ($this->option('fix') ? 'Re-run without --fix to verify.' : 'Re-run with --fix to apply automatic fixes where safe.'));
        return 1;
    }

    /**
     * Admin Blade files under `resources/views/vendor/vela/admin/` shadow
     * the package and become frozen forks — every package update silently
     * fails to reach them. They should never exist in a host app.
     */
    private function checkStaleAdminViewForks(): void
    {
        $adminDir = resource_path('views/vendor/vela/admin');
        if (! is_dir($adminDir)) {
            $this->components->twoColumnDetail('Admin view forks', '<fg=green>none</>');
            return;
        }

        $files = $this->listFiles($adminDir);
        $count = count($files);
        $this->issues++;

        $this->newLine();
        $this->components->error("Stale admin view forks detected ({$count} file(s) under resources/views/vendor/vela/admin/)");
        $this->line('  These shadow the package versions and hide future core updates.');
        $this->line('  Admin UI is not a fork point — extend it via registries on \\VelaBuild\\Core\\Vela.');
        $this->newLine();
        $sample = array_slice($files, 0, 5);
        foreach ($sample as $f) {
            $this->line('   • ' . $this->relPath($f));
        }
        if ($count > 5) {
            $this->line('   • … and ' . ($count - 5) . ' more');
        }

        if ($this->option('fix')) {
            $this->newLine();
            $this->line("  Removing {$adminDir} …");
            $this->rrmdir($adminDir);
            $this->components->info('Removed.');
        } else {
            $this->newLine();
            $this->line("  Run <fg=cyan>rm -rf {$adminDir}</> (or <fg=cyan>php artisan vela:doctor --fix</>) to remove.");
        }
    }

    /** @return list<string> absolute file paths */
    private function listFiles(string $dir): array
    {
        $out = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile()) $out[] = $f->getPathname();
        }
        sort($out);
        return $out;
    }

    private function relPath(string $abs): string
    {
        $base = base_path();
        return str_starts_with($abs, $base) ? ltrim(substr($abs, strlen($base)), DIRECTORY_SEPARATOR) : $abs;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
