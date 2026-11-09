<?php

namespace VelaBuild\Core\Tests\Feature\Marketplace;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use VelaBuild\Core\Jobs\RunPackageOperation;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Models\PackageLicense;
use VelaBuild\Core\Services\Marketplace\LicenseCacheWriter;
use VelaBuild\Core\Services\Marketplace\MarketplaceClient;
use VelaBuild\Core\Services\Marketplace\PackageInstaller;
use VelaBuild\Core\Tests\TestCase;

class PackageOperationTest extends TestCase
{
    use DatabaseTransactions;

    private function package(string $status = InstalledPackage::STATUS_ACTIVE): InstalledPackage
    {
        $name = 'acme/plug-' . uniqid();
        [$vendor, $pkg] = explode('/', $name);
        $package = InstalledPackage::create([
            'vendor_name' => $vendor, 'package_name' => $pkg, 'composer_name' => $name,
            'version' => '1.0.0', 'status' => $status, 'installed_at' => now(),
        ]);
        PackageLicense::create([
            'installed_package_id' => $package->id, 'license_key' => 'vela_key', 'domain' => 'localhost',
            'type' => 'onetime', 'validation_status' => 'valid',
        ]);
        return $package;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LicenseCacheWriter::class, fn ($m) => $m->shouldReceive('write')->andReturn(null));
    }

    public function test_a_failed_install_is_marked_failed_with_the_reason_and_log(): void
    {
        $package = $this->package(InstalledPackage::STATUS_INSTALLING);
        $this->mock(PackageInstaller::class, fn ($m) => $m->shouldReceive('install')->once()
            ->with($package->composer_name, 'vela_key')
            ->andReturn(['success' => false, 'output' => 'HTTP 401 from repo', 'error' => 'Composer require failed.']));

        app()->call([new RunPackageOperation($package->id, RunPackageOperation::INSTALL), 'handle']);

        $package->refresh();
        $this->assertSame(InstalledPackage::STATUS_FAILED, $package->status);
        $this->assertStringContainsString('Composer require failed', $package->operation_error);
        $this->assertStringContainsString('HTTP 401', $package->operation_log);
        $this->assertNotNull($package->operation_finished_at);
    }

    public function test_a_failed_update_keeps_the_working_package_active(): void
    {
        $package = $this->package(InstalledPackage::STATUS_UPDATING);
        $this->mock(PackageInstaller::class, fn ($m) => $m->shouldReceive('update')->once()
            ->andReturn(['success' => false, 'output' => '', 'error' => 'Composer update failed.']));

        app()->call([new RunPackageOperation($package->id, RunPackageOperation::UPDATE, InstalledPackage::STATUS_ACTIVE), 'handle']);

        $package->refresh();
        $this->assertSame(InstalledPackage::STATUS_ACTIVE, $package->status);
        $this->assertStringContainsString('Update failed', $package->operation_error);
    }

    public function test_a_crashed_job_never_leaves_the_package_stuck(): void
    {
        $package = $this->package(InstalledPackage::STATUS_INSTALLING);

        (new RunPackageOperation($package->id, RunPackageOperation::INSTALL))->failed(new \RuntimeException('timed out'));

        $this->assertSame(InstalledPackage::STATUS_FAILED, $package->fresh()->status);
    }

    public function test_remove_runs_in_the_background_and_busy_packages_refuse_other_actions(): void
    {
        $this->loginAsAdmin();
        Bus::fake();
        $package = $this->package();

        $this->delete('/admin/packages/' . $package->id)->assertSessionHas('message');
        $this->assertSame(InstalledPackage::STATUS_REMOVING, $package->fresh()->status);
        Bus::assertDispatched(RunPackageOperation::class, fn ($job) => $job->operation === 'remove' && $job->packageId === $package->id);

        $this->post('/admin/packages/' . $package->id . '/update')->assertSessionHas('error');
        $this->delete('/admin/packages/' . $package->id)->assertSessionHas('error');
    }

    public function test_a_successful_remove_deletes_the_package_and_a_reinstall_reuses_its_row(): void
    {
        $this->loginAsAdmin();
        $package = $this->package(InstalledPackage::STATUS_REMOVING);
        $this->mock(PackageInstaller::class, fn ($m) => $m->shouldReceive('remove')->once()->andReturn(['success' => true, 'output' => 'ok']));
        app()->call([new RunPackageOperation($package->id, RunPackageOperation::REMOVE), 'handle']);
        $this->assertSoftDeleted('vela_installed_packages', ['id' => $package->id]);

        Bus::fake();
        $this->mock(MarketplaceClient::class, function ($m) use ($package) {
            $m->shouldReceive('getPlugin')->andReturn(['slug' => 'plug', 'composer_name' => $package->composer_name, 'current_version' => '1.1.0']);
            $m->shouldReceive('validateLicense')->andReturn(['valid' => true, 'type' => 'onetime', 'domain' => 'localhost']);
        });

        $this->post('/admin/marketplace/plug/activate', ['license_key' => 'vela_key'])
            ->assertRedirect(route('vela.admin.packages.index'));

        $again = InstalledPackage::where('composer_name', $package->composer_name)->firstOrFail();
        $this->assertSame($package->id, $again->id, 'the soft-deleted row is restored, not duplicated');
        $this->assertSame(InstalledPackage::STATUS_INSTALLING, $again->status);
        Bus::assertDispatched(RunPackageOperation::class, fn ($job) => $job->operation === 'install');
    }

    public function test_retry_reinstalls_a_failed_package_and_status_reports_progress(): void
    {
        $this->loginAsAdmin();
        Bus::fake();
        $package = $this->package(InstalledPackage::STATUS_FAILED);

        $this->post('/admin/packages/' . $package->id . '/retry')->assertSessionHas('message');
        $this->assertSame(InstalledPackage::STATUS_INSTALLING, $package->fresh()->status);

        $this->getJson('/admin/packages/status')
            ->assertOk()
            ->assertJsonFragment(['id' => $package->id, 'status' => InstalledPackage::STATUS_INSTALLING]);
    }

    public function test_the_packages_page_shows_progress_failures_and_follows_the_job(): void
    {
        $this->loginAsAdmin();
        $busy = $this->package(InstalledPackage::STATUS_INSTALLING);
        $failed = $this->package(InstalledPackage::STATUS_FAILED);
        $failed->update(['operation_error' => 'Install failed: Composer require failed.', 'operation_log' => 'HTTP 401 from repo']);

        $this->get('/admin/packages')
            ->assertOk()
            ->assertSee('Installing…')
            ->assertSee('Please wait…')
            ->assertSee('Install failed')
            ->assertSee('Composer require failed.')
            ->assertSee('HTTP 401 from repo')
            ->assertSee(route('vela.admin.packages.retry', $failed->id), false)
            ->assertSee(json_encode(route('vela.admin.packages.status')), false); // polled while busy
    }
}
