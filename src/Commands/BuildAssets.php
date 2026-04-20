<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\AssetBundler;

class BuildAssets extends Command
{
    protected $signature = 'vela:assets:build
        {--bundle= : Build only a specific bundle (default: all)}
        {--no-minify : Skip minification (for debugging)}';

    protected $description = 'Combine + minify CSS/JS bundles into public/vendor/vela/bundles/';

    public function handle(AssetBundler $bundler): int
    {
        if ($this->option('no-minify')) {
            config(['vela.assets.minify' => false]);
        }

        $only = $this->option('bundle') ?: null;
        $manifest = $bundler->build($only);

        if (empty($manifest)) {
            $this->warn('No bundles built. Check config(vela.assets.bundles).');
            return 0;
        }

        foreach ($manifest as $key => $file) {
            $this->line("  {$key} → {$file}");
        }
        $this->info('Built ' . count($manifest) . ' bundle file(s).');
        return 0;
    }
}
