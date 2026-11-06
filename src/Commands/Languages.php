<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Jobs\GenerateStaticFilesJob;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\SiteConfigWriter;

/**
 * Show or set the site's languages, the same as Settings -> Languages.
 *
 *   php artisan vela:languages                       # show
 *   php artisan vela:languages en,fr,de --primary=en # set
 */
class Languages extends Command
{
    protected $signature = 'vela:languages
                            {languages? : Comma-separated language codes to enable}
                            {--primary= : The main language (default: the current one)}';

    protected $description = "Show or set this site's languages";

    public function handle(SiteConfigWriter $writer): int
    {
        $all = array_keys(SiteConfigWriter::allLanguages());
        $current = json_decode((string) VelaConfig::where('key', 'active_languages')->value('value'), true) ?: ['en'];
        $primary = $this->option('primary') ?: (VelaConfig::where('key', 'primary_language')->value('value') ?: 'en');

        if ($this->argument('languages') === null) {
            $this->line('Primary: ' . $primary);
            $this->line('Active:  ' . implode(', ', $current));

            return self::SUCCESS;
        }

        $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $this->argument('languages'))))));
        $unknown = array_diff(array_merge($codes, [$primary]), $all);
        if ($unknown) {
            $this->error('Unknown language: ' . implode(', ', $unknown) . '. Available: ' . implode(', ', $all));

            return self::FAILURE;
        }
        if (! in_array($primary, $codes, true)) {
            array_unshift($codes, $primary);
        }

        VelaConfig::updateOrCreate(['key' => 'active_languages'], ['value' => json_encode($codes)]);
        VelaConfig::updateOrCreate(['key' => 'primary_language'], ['value' => $primary]);
        $writer->write();
        GenerateStaticFilesJob::dispatchFresh('all');

        $this->info('Languages: ' . implode(', ', $codes) . " (primary {$primary})");

        return self::SUCCESS;
    }
}
