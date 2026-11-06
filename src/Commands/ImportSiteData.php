<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\SitePack\DataImporter;

/**
 * Host side of a Vela Hosted import: load an unpacked `vela:pack` archive's
 * rows into this site's empty database. Run `migrate` afterwards.
 * `vela-site import` runs this in a staging container; it is not meant for a
 * site that already has content (every packed table is replaced).
 */
class ImportSiteData extends Command
{
    protected $signature = 'vela:import-data
        {path : The unpacked archive (holds vela-manifest.json and data/)}
        {--force : Required: every packed table is replaced}';

    protected $description = 'Load a vela:pack archive\'s database rows into this site (replaces content)';

    public function handle(DataImporter $importer): int
    {
        if (!$this->option('force')) {
            $this->error('This replaces the site\'s content. Run again with --force.');

            return self::FAILURE;
        }
        $report = $importer->import((string) $this->argument('path'));
        $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
