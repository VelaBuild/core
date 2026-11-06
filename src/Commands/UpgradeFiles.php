<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use VelaBuild\Core\Services\CmsFileUpgrader;

/**
 * Bring the site's starter files (public/index.php, bootstrap/app.php,
 * config/*, routes/*, …) up to the versions this release of the package ships.
 *
 *   php artisan vela:upgrade-files              report only (same as --dry-run)
 *   php artisan vela:upgrade-files --auto       apply what is safe: files the site never changed, new files
 *   php artisan vela:upgrade-files -i           review the rest one by one: diff, overwrite, merge, keep, skip
 *   php artisan vela:upgrade-files --json       machine-readable report (with --auto: the actions too)
 *   php artisan vela:upgrade-files --path=config/ --path=routes/web.php
 *   php artisan vela:upgrade-files --strict     exit 2 if a critical file still needs review
 *
 * The admin has the same thing under Settings → CMS files.
 */
class UpgradeFiles extends Command
{
    protected $signature = 'vela:upgrade-files
        {--auto : Apply safe updates (unchanged files, new files) and record baselines}
        {--i|interactive : Review every file that needs a decision}
        {--dry-run : Only report (the default without --auto / --interactive)}
        {--json : Output JSON}
        {--path=* : Only these paths (a trailing / matches a directory)}
        {--strict : Exit with code 2 if a critical file still needs review}';

    protected $description = 'Find starter files this release of Vela has updated, and review, merge, overwrite or skip them';

    private const LABELS = [
        'current'            => '<fg=green>current</>',
        'update'             => '<fg=cyan>update (safe)</>',
        'add'                => '<fg=cyan>new (safe)</>',
        'review'             => '<fg=yellow>needs review</>',
        'customised'         => '<fg=gray>customised</>',
        'deleted'            => '<fg=gray>deleted by site</>',
        'remove'             => '<fg=yellow>removed upstream</>',
        'removed-customised' => '<fg=yellow>removed upstream (customised)</>',
        'kept'               => '<fg=gray>kept</>',
    ];

    public function handle(CmsFileUpgrader $upgrader): int
    {
        $only = (array) $this->option('path');
        $actions = [];

        if ($this->option('auto') && !$this->option('dry-run')) {
            $actions = $upgrader->applySafe($only, 'cli');
        }

        if ($this->option('interactive') && !$this->option('dry-run') && !$this->option('json')) {
            $actions = array_merge($actions, $this->review($upgrader, $only));
        }

        $report = $upgrader->report($only);

        if ($this->option('json')) {
            $this->line(json_encode([
                'version' => $upgrader->version(),
                'files'   => $report,
                'actions' => $actions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printReport($report, $actions);
        }

        if ($this->option('strict') && collect($report)->contains(fn ($i) => $i['critical'] && $i['status'] === 'review')) {
            return 2;
        }

        return collect($actions)->contains(fn ($a) => !$a['ok']) ? self::FAILURE : self::SUCCESS;
    }

    private function review(CmsFileUpgrader $upgrader, array $only): array
    {
        $done = [];
        foreach ($upgrader->report($only) as $item) {
            if (!in_array($item['status'], CmsFileUpgrader::PENDING, true)) {
                continue;
            }
            $this->newLine();
            $this->components->twoColumnDetail('<options=bold>' . $item['path'] . '</>', self::LABELS[$item['status']]);
            if (!empty($item['reason'])) {
                $this->line('  ' . $item['reason']);
            }

            while (true) {
                $choice = $this->choice('What should happen to it?', [...$item['actions'], 'diff'], 'skip');
                if ($choice === 'diff') {
                    $this->line($this->colourDiff($upgrader->diff($item['path'])));
                    continue;
                }
                $result = $upgrader->apply($item['path'], $choice, 'cli');
                if (!$result['ok'] && ($result['conflicts'] ?? 0) > 0
                    && $this->confirm("{$result['conflicts']} conflict(s). Write the merge with conflict markers for you to resolve?", false)) {
                    $result = $upgrader->apply($item['path'], 'merge', 'cli', allowConflicts: true);
                }
                if (!$result['ok']) {
                    $this->components->warn($result['message'] ?? 'not applied');
                    continue;
                }
                $done[] = $result;
                break;
            }
        }

        return $done;
    }

    private function printReport(array $report, array $actions): void
    {
        foreach ($actions as $a) {
            $this->components->twoColumnDetail($a['path'], $a['ok'] ? "<fg=green>{$a['action']}</>" : "<fg=red>{$a['action']} failed</>");
        }

        $interesting = array_filter($report, fn ($i) => $i['status'] !== 'current');
        if ($interesting === []) {
            $this->components->info('All ' . count($report) . ' starter files match this release.');

            return;
        }

        foreach ($interesting as $i) {
            $this->components->twoColumnDetail(
                $i['path'] . ($i['critical'] ? ' <fg=red>*</>' : ''),
                self::LABELS[$i['status']] . ($i['merge'] ? ' <fg=gray>(mergeable)</>' : ''),
            );
        }

        $pending = count(array_filter($report, fn ($i) => in_array($i['status'], CmsFileUpgrader::PENDING, true)));
        $safe = count(array_filter($report, fn ($i) => $i['safe']));
        $this->newLine();
        $this->line("  {$pending} need attention, {$safe} of them safe to apply. * = critical file.");
        if ($safe > 0) {
            $this->line('  Apply the safe ones: php artisan vela:upgrade-files --auto');
        }
        if ($pending > $safe) {
            $this->line('  Review the rest:     php artisan vela:upgrade-files -i   (or Settings → CMS files in the admin)');
        }
    }

    private function colourDiff(string $diff): string
    {
        return collect(explode("\n", $diff))->map(fn ($l) => match (true) {
            str_starts_with($l, '+') => '<fg=green>' . OutputFormatter::escape($l) . '</>',
            str_starts_with($l, '-') => '<fg=red>' . OutputFormatter::escape($l) . '</>',
            str_starts_with($l, '@@') => '<fg=cyan>' . OutputFormatter::escape($l) . '</>',
            default => OutputFormatter::escape($l),
        })->implode("\n");
    }
}
