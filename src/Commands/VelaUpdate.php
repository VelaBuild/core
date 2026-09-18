<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\AssetSync;

/**
 * What to run after `composer update velabuild/core`.
 *
 * A site does the file part of this by itself on its next page view; this is
 * the same thing on demand, with the migrations, for a deploy script or for a
 * host where the web user cannot write public/.
 */
class VelaUpdate extends Command
{
    protected $signature = 'vela:update {--no-migrate : Leave the database alone}';

    protected $description = 'Bring a site up to date after updating velabuild/core: migrations, public files, bundles, theme tokens';

    public function handle(AssetSync $sync): int
    {
        if (!$this->option('no-migrate')) {
            $this->call('migrate', ['--force' => true]);
        }

        // Whatever the admin was warning about has just been dealt with.
        \VelaBuild\Core\Services\SiteHealth::forget();

        try {
            $done = $sync->syncIfStale(force: true) ?? ['copied' => false, 'bundles' => 0, 'themes' => []];
        } catch (\Throwable $e) {
            // Run from composer's post-update hook, a failure here would stop
            // the whole install. Say what went wrong and let it carry on; the
            // site retries by itself on its next page view.
            $this->components->warn('Could not update the public files: ' . $e->getMessage());

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Public files', $done['copied'] ? '<fg=green>copied</>' : '<fg=yellow>skipped</>');
        $this->components->twoColumnDetail('Asset bundles', '<fg=green>' . $done['bundles'] . ' built</>');
        $this->components->twoColumnDetail('Theme tokens', $done['themes']
            ? '<fg=green>added to ' . implode(', ', $done['themes']) . '</>'
            : '<fg=green>nothing missing</>');

        return self::SUCCESS;
    }
}
