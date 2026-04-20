<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\Marketplace\MarketplaceClient;
use VelaBuild\Core\Services\Marketplace\PackageInstaller;

class PackageInstallCommand extends Command
{
    protected $signature = 'vela:package-install {composer_name : The package name (vendor/name)}';

    protected $description = 'Install a marketplace package via Composer';

    public function __construct(
        private PackageInstaller $installer,
        private MarketplaceClient $client
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

        $this->info('Installing package: ' . $composerName);
        $this->info('This may take a few minutes...');

        $result = $this->installer->install($composerName);

        if ($result['success']) {
            $this->info('Package installed successfully.');
            if (!empty($result['output'])) {
                $this->line($result['output']);
            }
            return 0;
        }

        $this->error('Package installation failed.');
        if (!empty($result['error'])) {
            $this->error($result['error']);
        }
        if (!empty($result['output'])) {
            $this->line($result['output']);
        }

        return 1;
    }
}
