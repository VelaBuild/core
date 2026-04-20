<?php

namespace VelaBuild\Core\Tests\Feature\Marketplace;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Services\Marketplace\PackageInstaller;
use VelaBuild\Core\Tests\TestCase;

class InstalledPackagesControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_index_lists_installed_packages(): void
    {
        $this->loginAsAdmin();

        InstalledPackage::create([
            'vendor_name' => 'acme',
            'package_name' => 'test-plugin',
            'composer_name' => 'acme/test-plugin',
            'version' => '1.0.0',
            'status' => InstalledPackage::STATUS_ACTIVE,
            'installed_at' => now(),
        ]);

        $response = $this->get('/admin/packages');

        $response->assertStatus(200);
        $response->assertSee('acme/test-plugin');
    }

    public function test_disable_toggles_package_status(): void
    {
        $this->loginAsAdmin();

        $package = InstalledPackage::create([
            'vendor_name' => 'acme',
            'package_name' => 'test-plugin',
            'composer_name' => 'acme/test-plugin-' . uniqid(),
            'version' => '1.0.0',
            'status' => InstalledPackage::STATUS_ACTIVE,
            'installed_at' => now(),
        ]);

        $response = $this->post('/admin/packages/' . $package->id . '/disable');

        $response->assertRedirect();

        $this->assertDatabaseHas('vela_installed_packages', [
            'id' => $package->id,
            'status' => InstalledPackage::STATUS_DISABLED,
        ]);
    }

    public function test_enable_toggles_package_status(): void
    {
        $this->loginAsAdmin();

        $package = InstalledPackage::create([
            'vendor_name' => 'acme',
            'package_name' => 'test-plugin',
            'composer_name' => 'acme/test-plugin-' . uniqid(),
            'version' => '1.0.0',
            'status' => InstalledPackage::STATUS_DISABLED,
            'installed_at' => now(),
        ]);

        $response = $this->post('/admin/packages/' . $package->id . '/enable');

        $response->assertRedirect();

        $this->assertDatabaseHas('vela_installed_packages', [
            'id' => $package->id,
            'status' => InstalledPackage::STATUS_ACTIVE,
        ]);
    }

    public function test_destroy_soft_deletes_package(): void
    {
        $this->loginAsAdmin();

        $package = InstalledPackage::create([
            'vendor_name' => 'acme',
            'package_name' => 'test-plugin',
            'composer_name' => 'acme/test-plugin-' . uniqid(),
            'version' => '1.0.0',
            'status' => InstalledPackage::STATUS_ACTIVE,
            'installed_at' => now(),
        ]);

        $this->mock(PackageInstaller::class, function ($mock) {
            $mock->shouldReceive('remove')->andReturn([
                'success' => true,
                'output' => 'Removed successfully',
            ]);
        });

        $response = $this->delete('/admin/packages/' . $package->id);

        $response->assertRedirect();

        $this->assertSoftDeleted('vela_installed_packages', [
            'id' => $package->id,
        ]);
    }

    public function test_operations_require_marketplace_install_permission(): void
    {
        $user = VelaUser::factory()->create();
        $role = Role::create(['title' => 'BrowseOnly_' . uniqid()]);
        $user->roles()->attach($role);
        $this->actingAs($user, 'vela');

        $package = InstalledPackage::create([
            'vendor_name' => 'acme',
            'package_name' => 'test-plugin',
            'composer_name' => 'acme/test-plugin-' . uniqid(),
            'version' => '1.0.0',
            'status' => InstalledPackage::STATUS_ACTIVE,
            'installed_at' => now(),
        ]);

        $response = $this->post('/admin/packages/' . $package->id . '/disable');

        $response->assertStatus(403);
    }
}
