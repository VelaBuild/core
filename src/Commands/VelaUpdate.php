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

        // Plugins' public files: replaced unless the site changed them.
        try {
            $plugin = app(\VelaBuild\Core\Services\PluginAssetSync::class)->sync();
            $this->components->twoColumnDetail('Plugin public files', $plugin['kept']
                ? '<fg=yellow>' . count($plugin['updated']) . ' updated, ' . count($plugin['kept']) . ' changed here and kept — php artisan vela:plugin-assets --diff</>'
                : '<fg=green>' . count($plugin['updated']) . ' updated</>');
            if ($plugin['backed_up']) {
                $this->components->twoColumnDetail('Previous copies saved in', $plugin['backup']);
            }
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('Plugin public files', '<fg=yellow>could not update: ' . $e->getMessage() . '</>');
        }

        // Template overrides are the site's own code too: reported, not changed.
        try {
            $outdated = count(app(\VelaBuild\Core\Services\TemplateVersions::class)->outdated());
            $this->components->twoColumnDetail('Template overrides', $outdated === 0
                ? '<fg=green>current</>'
                : '<fg=yellow>' . $outdated . ' older than the plugin — php artisan vela:templates</>');
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('Template overrides', '<fg=yellow>could not check: ' . $e->getMessage() . '</>');
        }

        // Starter files are the site's own code, so they are reported here,
        // not changed: `vela:upgrade-files --auto` applies the safe ones.
        try {
            $pending = collect(app(\VelaBuild\Core\Services\CmsFileUpgrader::class)->report())
                ->whereIn('status', \VelaBuild\Core\Services\CmsFileUpgrader::PENDING);
            $this->components->twoColumnDetail('Starter files', $pending->isEmpty()
                ? '<fg=green>current</>'
                : '<fg=yellow>' . $pending->count() . ' to review — php artisan vela:upgrade-files</>');
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('Starter files', '<fg=yellow>could not check: ' . $e->getMessage() . '</>');
        }

        return self::SUCCESS;
    }
}
