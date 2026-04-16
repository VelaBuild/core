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
        $this->info('Scanning for config files...');

        // Reset the daily cache key so it runs even if already ran today
        Cache::forget('import-content-ran:' . now()->toDateString());

        // Run the import synchronously (not queued)
        $job = new ImportContentFromConfigJob();
        $job->handle();

        $this->info('Import complete.');

        return Command::SUCCESS;
    }
}
