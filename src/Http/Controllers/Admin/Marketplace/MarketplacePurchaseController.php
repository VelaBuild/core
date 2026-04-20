<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Marketplace;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Models\PackageLicense;
use VelaBuild\Core\Services\Marketplace\LicenseCacheWriter;
use VelaBuild\Core\Services\Marketplace\MarketplaceClient;
use VelaBuild\Core\Services\Marketplace\MarketplaceSettingsService;
use VelaBuild\Core\Services\Marketplace\PackageInstaller;

class MarketplacePurchaseController extends Controller
{
    public function __construct(
        private MarketplaceClient $client,
        private PackageInstaller $installer,
        private LicenseCacheWriter $cacheWriter,
        private MarketplaceSettingsService $settings,
    ) {}

    public function callback(Request $request)
    {
        abort_if(Gate::denies('marketplace_install'), Response::HTTP_FORBIDDEN);

        $request->validate([
            'token' => 'required|string|max:64',
            'package' => 'required|string|regex:/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/',
        ]);

        $data = $this->client->exchangeToken($request->input('token'));

        if (isset($data['error']) || empty($data)) {
            return redirect()->route('vela.admin.marketplace.index')
                ->with('error', 'Purchase verification failed: ' . ($data['error'] ?? 'Unknown error'));
        }

        $composerName = $data['composer_name'] ?? null;

        if (!$composerName || !preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/', $composerName)) {
            return redirect()->route('vela.admin.marketplace.index')
                ->with('error', 'Purchase verification failed: Invalid package name received.');
        }

        $existing = InstalledPackage::where('composer_name', $composerName)->first();

        if ($existing) {
            $existing->license()->updateOrCreate(
                ['installed_package_id' => $existing->id],
                [
                    'license_key' => $data['license_key'] ?? '',
                    'domain' => $data['domain'] ?? $this->settings->getDomain(),
                    'dev_domain' => $data['dev_domain'] ?? null,
                    'type' => $data['type'] ?? PackageLicense::TYPE_ONETIME,
                    'expires_at' => $data['expires_at'] ?? null,
                    'validation_status' => PackageLicense::VALIDATION_VALID,
                    'marketplace_purchase_id' => $data['marketplace_purchase_id'] ?? null,
                ]
            );

            $this->cacheWriter->write();

            return redirect()->route('vela.admin.packages.index')
                ->with('success', 'License updated for ' . $composerName . '.');
        }

        $result = $this->installer->install($composerName);

        if (!$result['success']) {
            return redirect()->route('vela.admin.marketplace.index')
                ->with('error', 'Installation failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        [$vendorName, $packageName] = explode('/', $composerName, 2);

        $package = InstalledPackage::create([
            'vendor_name' => $vendorName,
            'package_name' => $packageName,
            'composer_name' => $composerName,
            'version' => $data['version'] ?? 'latest',
            'status' => InstalledPackage::STATUS_ACTIVE,
            'installed_at' => now(),
        ]);

        PackageLicense::create([
            'installed_package_id' => $package->id,
            'license_key' => $data['license_key'] ?? '',
            'domain' => $data['domain'] ?? $this->settings->getDomain(),
            'dev_domain' => $data['dev_domain'] ?? null,
            'type' => $data['type'] ?? PackageLicense::TYPE_ONETIME,
            'expires_at' => $data['expires_at'] ?? null,
            'validation_status' => PackageLicense::VALIDATION_VALID,
            'marketplace_purchase_id' => $data['marketplace_purchase_id'] ?? null,
        ]);

        $this->cacheWriter->write();

        $this->client->registerSite(
            $data['license_key'] ?? '',
            $this->settings->getDomain(),
            route('vela.webhook.marketplace')
        );

        $this->installer->ensureGitignoreHasAuthJson();

        return redirect()->route('vela.admin.packages.index')
            ->with('success', $composerName . ' installed successfully.');
    }
}
