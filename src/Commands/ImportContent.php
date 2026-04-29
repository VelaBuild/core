<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use VelaBuild\Core\Jobs\ImportContentFromConfigJob;

class ImportContent extends Command
{
    protected $signature = 'vela:import-content';
    protected $description = 'Import pages and posts from config JSON files into the database';

    public function handle(): int
    {
        $basePath = config('vela.static.path', resource_path('static'));
        $this->info('Scanning for config files in: ' . $basePath);

        // Reset the daily cache key so it runs even if already ran today
        Cache::forget('import-content-ran:' . now()->toDateString());

        $job = new ImportContentFromConfigJob();
        $job->handle();

        $this->newLine();
        foreach (['categories', 'pages', 'posts'] as $kind) {
            $r = $job->report[$kind];
            $this->line(sprintf(
                "  <fg=cyan>%-10s</> scanned=%d  created=%d  restored=%d  updated=%d",
                $kind, $r['scanned'], $r['created'], $r['restored'], $r['updated']
            ));
        }

        if (!empty($job->report['categories']['skipped'])) {
            $this->newLine();
            $this->line('  <fg=yellow>Skipped category folders:</>');
            foreach ($job->report['categories']['skipped'] as $reason) {
                $this->line('    - ' . $reason);
            }
        }

        $this->newLine();
        $this->info('Import complete.');

        return Command::SUCCESS;
    }
}
