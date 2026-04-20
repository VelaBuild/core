<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Marketplace;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Services\Marketplace\LicenseCacheWriter;
use VelaBuild\Core\Services\Marketplace\LicenseManager;
use VelaBuild\Core\Services\Marketplace\PackageInstaller;

class InstalledPackagesController extends Controller
{
    public function __construct(
        private PackageInstaller $installer,
        private LicenseManager $licenseManager,
        private LicenseCacheWriter $cacheWriter,
    ) {}

    public function index()
    {
        abort_if(Gate::denies('marketplace_browse'), Response::HTTP_FORBIDDEN);

        $packages = InstalledPackage::with('license')->orderBy('composer_name')->get();
        $safeMode = $this->licenseManager->isSafeMode();

        return view('vela::admin.packages.index', compact('packages', 'safeMode'));
    }

    public function disable(int $id)
    {
        abort_if(Gate::denies('marketplace_install'), Response::HTTP_FORBIDDEN);

        $package = InstalledPackage::findOrFail($id);
        $package->update(['status' => InstalledPackage::STATUS_DISABLED]);
        $this->cacheWriter->write();

        return redirect()->back()->with('success', 'Package disabled.');
    }

    public function enable(int $id)
    {
        abort_if(Gate::denies('marketplace_install'), Response::HTTP_FORBIDDEN);

        $package = InstalledPackage::findOrFail($id);
        $package->update(['status' => InstalledPackage::STATUS_ACTIVE]);
        $this->cacheWriter->write();

        return redirect()->back()->with('success', 'Package enabled.');
    }

    public function update(Request $request, int $id)
    {
        abort_if(Gate::denies('marketplace_install'), Response::HTTP_FORBIDDEN);

        $package = InstalledPackage::findOrFail($id);

        if (!$package->license || $this->licenseManager->isExpired($package->license)) {
            return redirect()->back()->with('error', 'Cannot update: license is expired or missing.');
        }

        $result = $this->installer->update($package->composer_name);

        if (!$result['success']) {
            return redirect()->back()->with('error', $result['error'] ?? 'Update failed.');
        }

        $package->update(['version' => $result['version'] ?? $package->version]);
        $this->cacheWriter->write();

        return redirect()->back()->with('success', 'Package updated successfully.');
    }

    public function destroy(int $id)
    {
        abort_if(Gate::denies('marketplace_install'), Response::HTTP_FORBIDDEN);

        $package = InstalledPackage::findOrFail($id);

        $result = $this->installer->remove($package->composer_name);

        if (!$result['success']) {
            return redirect()->back()->with('error', $result['error'] ?? 'Removal failed.');
        }

        $package->license?->delete();
        $package->delete();
        $this->cacheWriter->write();

        return redirect()->back()->with('success', 'Package removed successfully.');
    }
}
