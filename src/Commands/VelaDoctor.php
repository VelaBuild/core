<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;

/**
 * Project-health diagnostics — detects misconfigurations and dangerous
 * leftover state in a Vela host app.
 *
 *   php artisan vela:doctor
 *   php artisan vela:doctor --fix    # auto-remove safe leftovers
 *
 * Currently checks:
 *   - Forked Blade directories under resources/views/vendor/vela/ that
 *     shadow the package and silently hide future updates. Each forkable
 *     dir is classified by severity (see $forkPolicy):
 *
 *         critical → admin/, partials/, layouts/, components/
 *                    (admin chrome — must never be forked)
 *         high     → auth/, csvImport/, pwa/
 *         medium   → public/  (frontend — sometimes legit, but warn)
 *         info     → errors/  (inert; Laravel reads from views/errors/)
 *         (exempt) → templates/  (intentional public theme forks)
 */
class VelaDoctor extends Command
{
    protected $signature = 'vela:doctor {--fix : Auto-remove fork directories rated critical/high/info}';
    protected $description = 'Diagnose common configuration issues in a Vela host app.';

    /**
     * Per-directory severity. Anything not listed (e.g. `templates/`) is
     * left alone — those are legitimate publish points for site themes.
     */
    private array $forkPolicy = [
        'admin'      => ['severity' => 'critical', 'reason' => 'Admin UI must not be forked — extend via registries on \\VelaBuild\\Core\\Vela.'],
        'partials'   => ['severity' => 'critical', 'reason' => 'Shared admin chrome — forking shadows package updates (datatables actions, AI chatbot, command palette, …).'],
        'layouts'    => ['severity' => 'critical', 'reason' => 'Layout files are package-managed; fork breaks navbar / sidebar updates.'],
        'components' => ['severity' => 'critical', 'reason' => 'Anonymous Blade components (<x-vela::…>) — forks defeat new component additions.'],
        'auth'       => ['severity' => 'high',     'reason' => 'Auth screens (login/register) — forks miss security fixes.'],
        'csvImport'  => ['severity' => 'high',     'reason' => 'CSV-import widget shared across CRUDs.'],
        'pwa'        => ['severity' => 'high',     'reason' => 'Service worker / offline page; bugs here break the PWA.'],
        'public'     => ['severity' => 'medium',   'reason' => 'Public-facing partials. Sometimes legitimately customised — review before deleting.'],
        'errors'     => ['severity' => 'info',     'reason' => 'Inert: Laravel reads error pages from resources/views/errors/, not vendor/vela/errors/. Safe to delete.'],
    ];

    private int $issues = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('vela:doctor — host app health check');

        $this->checkStaleViewForks();

        $this->newLine();
        if ($this->issues === 0) {
            $this->components->info('No issues found.');
            return 0;
        }
        $this->components->warn(
            "{$this->issues} issue(s) detected. " .
            ($this->option('fix') ? 'Re-run without --fix to verify.' : 'Re-run with --fix to remove forks (templates/ + medium-severity public/ are kept).')
        );
        return 1;
    }

    /**
     * Walk every direct child of resources/views/vendor/vela/ and classify
     * by the policy table. Intentional publish points (templates/) are
     * silently passed over.
     */
    private function checkStaleViewForks(): void
    {
        $base = resource_path('views/vendor/vela');
        if (! is_dir($base)) {
            $this->components->twoColumnDetail('View forks under vendor/vela/', '<fg=green>none</>');
            return;
        }

        $found = [];
        foreach ($this->forkPolicy as $dir => $policy) {
            $path = $base . DIRECTORY_SEPARATOR . $dir;
            if (! is_dir($path)) continue;
            $files = $this->listFiles($path);
            if (! $files) continue;
            $found[$dir] = ['policy' => $policy, 'path' => $path, 'files' => $files];
        }

        if (! $found) {
            $this->components->twoColumnDetail('View forks under vendor/vela/', '<fg=green>none</>');
            return;
        }

        foreach ($found as $dir => $info) {
            $count = count($info['files']);
            $sev = $info['policy']['severity'];
            $tag = match ($sev) {
                'critical' => '<fg=red;options=bold>critical</>',
                'high'     => '<fg=red>high</>',
                'medium'   => '<fg=yellow>medium</>',
                'info'     => '<fg=gray>info</>',
                default    => $sev,
            };
            $this->issues++;

            $this->newLine();
            $this->line(" [{$tag}] <options=bold>vendor/vela/{$dir}/</> — {$count} file(s)");
            $this->line('   ' . $info['policy']['reason']);

            $sample = array_slice($info['files'], 0, 4);
            foreach ($sample as $f) {
                $this->line('     • ' . $this->relPath($f));
            }
            if ($count > 4) {
                $this->line('     • … and ' . ($count - 4) . ' more');
            }

            if ($this->option('fix') && in_array($sev, ['critical', 'high', 'info'], true)) {
                $this->line("   <fg=cyan>removing…</>");
                $this->rrmdir($info['path']);
                $this->line('   <fg=green>removed.</>');
            } elseif ($this->option('fix') && $sev === 'medium') {
                $this->line("   <fg=yellow>--fix skips medium-severity forks; review and run</> <fg=cyan>rm -rf {$info['path']}</> <fg=yellow>manually if appropriate.</>");
            } else {
                $this->line("   <fg=gray>fix:</> <fg=cyan>rm -rf {$info['path']}</>");
            }
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
