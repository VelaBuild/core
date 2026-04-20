<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Services\Marketplace\LicenseCacheWriter;
use VelaBuild\Core\Services\Marketplace\PackageInstaller;

class PackageRemoveCommand extends Command
{
    protected $signature = 'vela:package-remove {composer_name : The package name (vendor/name)}';

    protected $description = 'Remove an installed marketplace package';

    public function __construct(
        private PackageInstaller $installer,
        private LicenseCacheWriter $cacheWriter
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $composerName = $this->argument('composer_name');

        if (!preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/', $composerName)) {
            $this->error('Invalid Composer package name: ' . $composerName);
            return 1;
        }

        $this->info('Removing package: ' . $composerName);

        $result = $this->installer->remove($composerName);

        if (!$result['success']) {
            $this->error('Package removal failed.');
            if (!empty($result['error'])) {
                $this->error($result['error']);
            }
            if (!empty($result['output'])) {
                $this->line($result['output']);
            }
            return 1;
        }

        $package = InstalledPackage::where('composer_name', $composerName)->first();
        if ($package) {
            if ($package->license) {
                $package->license->delete();
            }
            $package->delete();
        }

        $this->cacheWriter->write();

        $this->info('Package removed successfully.');
        if (!empty($result['output'])) {
            $this->line($result['output']);
        }

        return 0;
    }
}
