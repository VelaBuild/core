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
use VelaBuild\Core\Services\PluginAssetSync;
use VelaBuild\Core\Services\TemplateVersions;

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
        $orphans = $this->orphans($packages);

        // What the site keeps its own copy of, and the plugin has moved past.
        $templates = $this->safely(fn () => app(TemplateVersions::class)->outdated());
        $changedAssets = $this->safely(fn () => app(PluginAssetSync::class)->modified());

        return view('vela::admin.packages.index', compact('packages', 'safeMode', 'orphans', 'templates', 'changedAssets'));
    }

    /** A template override against the plugin's template, as plain text. */
    public function templateDiff(Request $request)
    {
        abort_if(Gate::denies('marketplace_browse'), Response::HTTP_FORBIDDEN);

        try {
            $diff = app(TemplateVersions::class)->diff((string) $request->query('path', ''));
        } catch (\InvalidArgumentException) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response($diff, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** A plugin public file the site changed against the plugin's, as plain text. */
    public function assetDiff(Request $request)
    {
        abort_if(Gate::denies('marketplace_browse'), Response::HTTP_FORBIDDEN);

        try {
            $diff = app(PluginAssetSync::class)->diff((string) $request->query('path', ''));
        } catch (\InvalidArgumentException) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response($diff, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** A check that fails must not take the page down with it. */
    private function safely(callable $check): array
    {
        try {
            return $check();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
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
     * Install a package again over itself and redo package:discover and the
     * migrations, for a plugin that is installed but not working (e.g. one
     * put on disk by an older installer that never registered it). Its data
     * is kept.
     */
    public function repair(int $id)
    {
        abort_if(Gate::denies('marketplace_install'), Response::HTTP_FORBIDDEN);

        $package = InstalledPackage::findOrFail($id);

        if ($package->isBusy()) {
            return redirect()->back()->with('error', $package->composer_name . ' is already being worked on.');
        }

        RunPackageOperation::start($package, $package->status === InstalledPackage::STATUS_FAILED
            ? RunPackageOperation::INSTALL
            : RunPackageOperation::REPAIR);

        return redirect()->back()->with('message', 'Repairing ' . $package->composer_name . ' in the background.');
    }

    /**
     * Remove a marketplace package Composer still has but the database lost.
     */
    public function removeOrphan(Request $request)
    {
        abort_if(Gate::denies('marketplace_install'), Response::HTTP_FORBIDDEN);

        $name = (string) $request->input('composer_name');
        // Only a name the lock file itself lists as a marketplace package.
        abort_unless(array_key_exists($name, $this->orphans(InstalledPackage::get())), Response::HTTP_UNPROCESSABLE_ENTITY);

        [$vendor, $pkg] = explode('/', $name, 2);
        $package = InstalledPackage::withTrashed()->firstOrNew(['composer_name' => $name]);
        if ($package->trashed()) {
            $package->restore();
        }
        $package->fill([
            'vendor_name' => $vendor,
            'package_name' => $pkg,
            'version' => $package->version ?: 'unknown',
            'status' => InstalledPackage::STATUS_ACTIVE,
        ])->save();

        RunPackageOperation::start($package, RunPackageOperation::REMOVE);

        return redirect()->back()->with('message', 'Removing ' . $name . ' in the background.');
    }

    /**
     * Marketplace packages in composer.lock with no live row in the database.
     *
     * @return array<string, string>
     */
    private function orphans($packages): array
    {
        $known = $packages->pluck('composer_name')->all();

        return array_diff_key($this->installer->marketplacePackagesInLock(), array_flip($known));
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
