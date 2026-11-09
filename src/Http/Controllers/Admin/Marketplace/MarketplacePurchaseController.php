<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Marketplace;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Jobs\RunPackageOperation;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Models\PackageLicense;
use VelaBuild\Core\Services\Marketplace\LicenseCacheWriter;
use VelaBuild\Core\Services\Marketplace\MarketplaceClient;
use VelaBuild\Core\Services\Marketplace\MarketplaceSettingsService;
use VelaBuild\Core\Services\Marketplace\PackageInstaller;

class MarketplacePurchaseController extends Controller
{
    /**
     * A composer package name, as composer itself defines one.
     *
     * Held here because it is asked twice — once of what the browser came
     * back with, once of what the marketplace says was bought — and the two
     * had been written out separately.
     */
    private const COMPOSER_NAME = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';

    public function __construct(
        private MarketplaceClient $client,
        private PackageInstaller $installer,
        private LicenseCacheWriter $cacheWriter,
        private MarketplaceSettingsService $settings,
    ) {}

    public function callback(Request $request)
    {
        abort_if(Gate::denies('marketplace_install'), Response::HTTP_FORBIDDEN);

        // Rules as an array, not a pipe-delimited string. The pattern below
        // contains a `|` of its own, and Laravel splits a rule STRING on that
        // character: the regex arrived at preg_match() cut off mid-pattern,
        // with no closing delimiter, and every purchase coming back from the
        // marketplace answered 500 instead of installing anything.
        $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'package' => ['required', 'string', 'regex:' . self::COMPOSER_NAME],
        ]);

        $data = $this->client->exchangeToken($request->input('token'));

        if (isset($data['error']) || empty($data)) {
            return redirect()->route('vela.admin.marketplace.index')
                ->with('error', 'Purchase verification failed: ' . ($data['error'] ?? 'Unknown error'));
        }

        $composerName = $data['composer_name'] ?? null;

        if (!$composerName || !preg_match(self::COMPOSER_NAME, $composerName)) {
            return redirect()->route('vela.admin.marketplace.index')
                ->with('error', 'Purchase verification failed: Invalid package name received.');
        }

        return $this->installWithLicense($composerName, $data);
    }

    /**
     * Install a plugin with a license key the site owner already holds.
     *
     * A license can reach someone without a checkout on this site: granted by
     * the Vela team, bought from the marketplace website, or bought before the
     * site was rebuilt. Without this, the only way in was "Purchase & Install",
     * which sends them to pay again for what they already own.
     */
    public function activate(Request $request, string $slug)
    {
        abort_if(Gate::denies('marketplace_install'), Response::HTTP_FORBIDDEN);

        $request->validate([
            'license_key' => ['required', 'string', 'max:128'],
        ]);

        $plugin = $this->client->getPlugin($slug);

        abort_if(is_null($plugin), Response::HTTP_NOT_FOUND);

        $composerName = $plugin['composer_name'] ?? null;

        if (!$composerName || !preg_match(self::COMPOSER_NAME, $composerName)) {
            return $this->backToPlugin($slug, 'This plugin has no valid package name, so it cannot be installed.');
        }

        $licenseKey = trim($request->input('license_key'));
        $domain = $this->settings->getDomain();
        $result = $this->client->validateLicense($licenseKey, $domain);

        if (empty($result['valid'])) {
            $reason = match ($result['reason'] ?? null) {
                'domain_mismatch' => 'This license key belongs to a different domain. It must be issued for ' . $domain . '.',
                'expired' => 'This license key has expired.',
                'revoked', 'suspended' => 'This license key is no longer active.',
                default => 'The marketplace did not recognise this license key.',
            };

            return $this->backToPlugin($slug, $reason);
        }

        // The marketplace does not yet say which plugin a key is for. When it
        // does, refuse a key bought for one plugin being spent on another.
        if (isset($result['composer_name']) && $result['composer_name'] !== $composerName) {
            return $this->backToPlugin($slug, 'This license key is for a different plugin.');
        }

        return $this->installWithLicense($composerName, array_merge($result, [
            'license_key' => $licenseKey,
            'version' => $plugin['current_version'] ?? null,
        ]));
    }

    /**
     * Install the package (unless it is already here) and keep its license.
     *
     * @param  array<string, mixed>  $data  license details from the marketplace
     */
    private function installWithLicense(string $composerName, array $data)
    {
        $licenseFields = [
            'license_key' => $data['license_key'] ?? '',
            'domain' => $data['domain'] ?? $this->settings->getDomain(),
            'dev_domain' => $data['dev_domain'] ?? null,
            'type' => $data['type'] ?? PackageLicense::TYPE_ONETIME,
            'expires_at' => $data['expires_at'] ?? null,
            'validation_status' => PackageLicense::VALIDATION_VALID,
            // The marketplace answers "purchase_id"; "marketplace_purchase_id" was never sent.
            'marketplace_purchase_id' => $data['purchase_id'] ?? $data['marketplace_purchase_id'] ?? null,
        ];

        // Removed packages are soft-deleted and composer_name is unique, so a
        // reinstall reuses the old row; creating a new one failed.
        $existing = InstalledPackage::withTrashed()->where('composer_name', $composerName)->first();

        if ($existing && !$existing->trashed() && $existing->status !== InstalledPackage::STATUS_FAILED) {
            $existing->license()->updateOrCreate(
                ['installed_package_id' => $existing->id],
                $licenseFields
            );

            $this->cacheWriter->write();

            return redirect()->route('vela.admin.packages.index')
                ->with('message', $existing->isBusy()
                    ? 'License saved. ' . $composerName . ' is still being worked on.'
                    : 'License updated for ' . $composerName . '.');
        }

        [$vendorName, $packageName] = explode('/', $composerName, 2);

        if ($existing) {
            $existing->restore();
            $package = $existing;
            $package->update(['version' => $data['version'] ?? $package->version ?? 'latest']);
        } else {
            $package = InstalledPackage::create([
                'vendor_name' => $vendorName,
                'package_name' => $packageName,
                'composer_name' => $composerName,
                'version' => $data['version'] ?? 'latest',
                'status' => InstalledPackage::STATUS_INSTALLING,
            ]);
        }

        $package->license()->updateOrCreate(['installed_package_id' => $package->id], $licenseFields);
        $this->cacheWriter->write();

        // Composer runs in the background; the packages page follows it.
        RunPackageOperation::start($package, RunPackageOperation::INSTALL);

        return redirect()->route('vela.admin.packages.index')
            ->with('message', 'Installing ' . $composerName . '. This can take a few minutes — you can leave this page.');
    }

    private function backToPlugin(string $slug, string $message)
    {
        return redirect()->route('vela.admin.marketplace.show', $slug)
            ->with('error', $message);
    }
}
