<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\SitePack\SitePacker;

/**
 * Pack this site into one file for Vela Hosted: database rows, uploads,
 * themes and your own code (not vendor/). Upload it in the hosting panel under
 * your site → Import, or use `php artisan vela:push`, which packs and uploads.
 *
 *   php artisan vela:pack                       → vela-site-<date>.tar.gz here
 *   php artisan vela:pack --output=/tmp/site.tar.gz
 */
class PackSite extends Command
{
    protected $signature = 'vela:pack
        {--output= : Where to write the archive}
        {--json : Output JSON}';

    protected $description = 'Pack this site (database, uploads, themes) into one file for Vela Hosted';

    public function handle(): int
    {
        $packer = new SitePacker();
        if ($blockers = $packer->blockers()) {
            foreach ($blockers as $b) {
                $this->error($b);
            }

            return self::FAILURE;
        }

        $output = $this->option('output') ?: 'vela-site-' . gmdate('Ymd-His') . '.tar.gz';
        $result = $packer->pack($output, fn ($step) => $this->option('json') ? null : $this->line("· {$step}"));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $rows = array_sum($result['manifest']['tables']);
        $this->info(sprintf('Packed %s: %d files, %d tables (%d rows), %s.', $result['path'], $result['files'],
            count($result['manifest']['tables']), $rows, self::size($result['bytes'])));
        $this->line('Upload it in the Vela Hosted panel (your site → Import), or run php artisan vela:push.');

        return self::SUCCESS;
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
