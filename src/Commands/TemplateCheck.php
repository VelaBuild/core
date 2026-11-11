<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\TemplateVersions;

/**
 * List the site's template overrides that are older than the plugin's.
 *
 * Nothing is changed: an override is the site's own code. With --diff, each
 * outdated one is shown against the plugin's template, for the owner (or an
 * AI) to bring across what the new version changed.
 */
class TemplateCheck extends Command
{
    protected $signature = 'vela:templates
        {--all : List current overrides too}
        {--diff : Show each outdated override against the plugin\'s template}';

    protected $description = 'Find template overrides in resources/views/vendor that are older than the plugin\'s template';

    public function handle(TemplateVersions $versions): int
    {
        $report = $versions->report();
        TemplateVersions::forget();

        if ($report === []) {
            $this->components->info('This site overrides no versioned plugin template.');

            return self::SUCCESS;
        }

        $rows = $this->option('all') ? $report : array_values(array_filter($report, fn ($t) => $t['status'] === TemplateVersions::OUTDATED));
        if ($rows === []) {
            $this->components->info(count($report) . ' template ' . (count($report) === 1 ? 'override is' : 'overrides are') . ' up to date.');

            return self::SUCCESS;
        }

        $this->table(['Template', 'Package', 'Site', 'Plugin', 'Status'], array_map(fn ($t) => [
            $t['path'],
            $t['package'] ?? $t['namespace'],
            $t['site_version'] ?? '-',
            $t['plugin_version'],
            $t['status'] === TemplateVersions::OUTDATED ? '<fg=yellow>outdated</>' : '<fg=green>current</>',
        ], $rows));

        if ($this->option('diff')) {
            foreach ($rows as $t) {
                if ($t['status'] === TemplateVersions::OUTDATED) {
                    $this->newLine();
                    $this->output->writeln($versions->diff($t['path']), \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
                }
            }
        }

        $outdated = count(array_filter($report, fn ($t) => $t['status'] === TemplateVersions::OUTDATED));
        if ($outdated > 0) {
            $this->newLine();
            $this->line('Bring the plugin\'s changes into each outdated file, then set its @version to the plugin\'s.');
            if (!$this->option('diff')) {
                $this->line('See what changed: php artisan vela:templates --diff');
            }
        }

        return self::SUCCESS;
    }
}
