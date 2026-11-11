<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\PluginAssetSync;

/**
 * Copy the public files plugins publish, keeping any the site changed.
 *
 * Run by the marketplace after every install, update and repair, and by
 * vela:update; see PluginAssetSync for how a changed file is told apart.
 */
class PluginAssets extends Command
{
    protected $signature = 'vela:plugin-assets
        {--check : Only list the files the site changed; copy nothing}
        {--diff : Show how each file the site changed differs from the plugin\'s}
        {--keep=* : Keep the site\'s version of this file (path under public/) and stop reporting it}';

    protected $description = 'Update the CSS, JS and images plugins publish to public/, keeping files the site has changed';

    public function handle(PluginAssetSync $sync): int
    {
        foreach ((array) $this->option('keep') as $path) {
            $path = preg_replace('#^public/#', '', (string) $path);
            try {
                $sync->keep($path);
                $this->components->info("Keeping this site's version of public/{$path}.");
            } catch (\InvalidArgumentException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        }
        if ($this->option('keep')) {
            return self::SUCCESS;
        }

        if (!$this->option('check')) {
            $done = $sync->sync();

            $this->components->twoColumnDetail('Plugin public files', '<fg=green>' . count($done['updated']) . ' updated</>');
            if ($done['backed_up']) {
                $this->components->twoColumnDetail('Previous copies saved in', $done['backup']);
            }
        }

        $kept = $sync->modified();
        if ($kept === []) {
            $this->components->info('No plugin file has local changes.');

            return self::SUCCESS;
        }

        $this->components->warn(count($kept) . ' plugin ' . (count($kept) === 1 ? 'file was' : 'files were')
            . ' changed on this site, so the plugin\'s newer version was not copied over it:');
        foreach ($kept as $path) {
            $this->line('  public/' . $path);
            if ($this->option('diff')) {
                $this->output->writeln($sync->diff($path), \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
            }
        }
        $this->newLine();
        $this->line('To keep your version: php artisan vela:plugin-assets --keep=<path>');
        $this->line('To take the plugin\'s version, delete the file and run this command again.');

        return self::SUCCESS;
    }
}
