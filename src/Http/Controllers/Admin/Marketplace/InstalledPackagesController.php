<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Marketplace;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Jobs\RunPackageOperation;
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

        if ($package->isBusy()) {
            return redirect()->back()->with('error', $package->composer_name . ' is already being worked on.');
        }
        if (!$package->license || $this->licenseManager->isExpired($package->license)) {
            return redirect()->back()->with('error', 'Cannot update: license is expired or missing.');
        }

        RunPackageOperation::start($package, RunPackageOperation::UPDATE);

        return redirect()->back()->with('message', 'Updating ' . $package->composer_name . ' in the background.');
    }

    public function destroy(int $id)
    {
        abort_if(Gate::denies('marketplace_install'), Response::HTTP_FORBIDDEN);

        $package = InstalledPackage::findOrFail($id);

        if ($package->isBusy()) {
            return redirect()->back()->with('error', $package->composer_name . ' is already being worked on.');
        }

        // A failed install never reached Composer's lock file: just forget it.
        if ($package->status === InstalledPackage::STATUS_FAILED) {
            $package->license?->delete();
            $package->delete();
            $this->cacheWriter->write();
            return redirect()->back()->with('message', $package->composer_name . ' removed.');
        }

        RunPackageOperation::start($package, RunPackageOperation::REMOVE);

        return redirect()->back()->with('message', 'Removing ' . $package->composer_name . ' in the background.');
    }

    /**
     * Try a failed install again with the license it already has.
     */
    public function retry(int $id)
    {
        abort_if(Gate::denies('marketplace_install'), Response::HTTP_FORBIDDEN);

        $package = InstalledPackage::findOrFail($id);
        abort_unless($package->status === InstalledPackage::STATUS_FAILED, Response::HTTP_UNPROCESSABLE_ENTITY);

        RunPackageOperation::start($package, RunPackageOperation::INSTALL);

        return redirect()->back()->with('message', 'Installing ' . $package->composer_name . ' again.');
    }

    /**
     * What the packages page polls while something is running.
     */
    public function status()
    {
        abort_if(Gate::denies('marketplace_browse'), Response::HTTP_FORBIDDEN);

        return response()->json(
            InstalledPackage::orderBy('composer_name')->get(['id', 'composer_name', 'status', 'operation_error'])
        )->header('Cache-Control', 'no-store');
    }
}
