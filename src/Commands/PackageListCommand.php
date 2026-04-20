<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Models\InstalledPackage;

class PackageListCommand extends Command
{
    protected $signature = 'vela:package-list';

    protected $description = 'List all installed marketplace packages';

    public function handle(): int
    {
        $packages = InstalledPackage::with('license')->orderBy('composer_name')->get();

        if ($packages->isEmpty()) {
            $this->info('No marketplace packages installed.');
            return 0;
        }

        $rows = $packages->map(function (InstalledPackage $package) {
            $license = $package->license;

            return [
                $package->composer_name,
                $package->version,
                $license ? $license->type : 'N/A',
                $license ? $license->validation_status : 'N/A',
                $license && $license->expires_at ? $license->expires_at->format('Y-m-d') : '—',
                $license ? $license->domain : 'N/A',
            ];
        })->toArray();

        $this->table(
            ['Name', 'Version', 'License Type', 'License Status', 'Expires At', 'Domain'],
            $rows
        );

        return 0;
    }
}
