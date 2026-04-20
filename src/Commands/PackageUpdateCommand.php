<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Services\Marketplace\LicenseManager;
use VelaBuild\Core\Services\Marketplace\PackageInstaller;

class PackageUpdateCommand extends Command
{
    protected $signature = 'vela:package-update {composer_name? : Update specific package or all}';

    protected $description = 'Update an installed marketplace package or all packages';

    public function __construct(
        private PackageInstaller $installer,
        private LicenseManager $licenseManager
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $composerName = $this->argument('composer_name');

        if ($composerName !== null) {
            if (!preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/', $composerName)) {
                $this->error('Invalid Composer package name: ' . $composerName);
                return 1;
            }

            return $this->updatePackage($composerName);
        }

        $packages = InstalledPackage::active()->with('license')->get();

        if ($packages->isEmpty()) {
            $this->info('No active marketplace packages to update.');
            return 0;
        }

        $exitCode = 0;

        foreach ($packages as $package) {
            if ($package->license && $this->licenseManager->isExpired($package->license)) {
                $this->warn('Skipping ' . $package->composer_name . ': license is expired.');
                continue;
            }

            $result = $this->updatePackage($package->composer_name);
            if ($result !== 0) {
                $exitCode = 1;
            }
        }

        return $exitCode;
    }

    private function updatePackage(string $composerName): int
    {
        $this->info('Updating package: ' . $composerName);

        $result = $this->installer->update($composerName);

        if ($result['success']) {
            $this->info('Package updated successfully: ' . $composerName);
            if (!empty($result['output'])) {
                $this->line($result['output']);
            }
            return 0;
        }

        $this->error('Package update failed: ' . $composerName);
        if (!empty($result['error'])) {
            $this->error($result['error']);
        }
        if (!empty($result['output'])) {
            $this->line($result['output']);
        }

        return 1;
    }
}
