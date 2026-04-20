<?php

namespace VelaBuild\Core\Tests\Feature\Marketplace;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Services\Marketplace\LicenseCacheWriter;
use VelaBuild\Core\Services\Marketplace\MarketplaceClient;
use VelaBuild\Core\Services\Marketplace\PackageInstaller;
use VelaBuild\Core\Tests\TestCase;

class MarketplacePurchaseCallbackTest extends TestCase
{
    use DatabaseTransactions;

    public function test_callback_with_valid_token_creates_records(): void
    {
        $this->loginAsAdmin();

        $composerName = 'acme/test-' . uniqid();

        $this->mock(MarketplaceClient::class, function ($mock) use ($composerName) {
            $mock->shouldReceive('exchangeToken')->with('abc')->andReturn([
                'license_key' => 'vela_test123',
                'composer_name' => $composerName,
                'type' => 'onetime',
                'domain' => 'test.com',
                'dev_domain' => null,
                'expires_at' => null,
                'marketplace_purchase_id' => '123',
            ]);
            $mock->shouldReceive('registerSite')->andReturn(true);
        });

        $this->mock(PackageInstaller::class, function ($mock) {
            $mock->shouldReceive('install')->andReturn([
                'success' => true,
                'output' => 'OK',
            ]);
            $mock->shouldReceive('ensureGitignoreHasAuthJson')->andReturn(null);
        });

        $this->mock(LicenseCacheWriter::class, function ($mock) {
            $mock->shouldReceive('write')->andReturn(null);
        });

        [$vendor, $package] = explode('/', $composerName, 2);

        $response = $this->get('/admin/marketplace/purchase/callback?token=abc&package=' . urlencode($composerName));

        $response->assertRedirect();

        $this->assertDatabaseHas('vela_installed_packages', [
            'composer_name' => $composerName,
            'status' => InstalledPackage::STATUS_ACTIVE,
        ]);
    }

    public function test_callback_with_invalid_token_redirects_with_error(): void
    {
        $this->loginAsAdmin();

        $this->mock(MarketplaceClient::class, function ($mock) {
            $mock->shouldReceive('exchangeToken')->andReturn(['error' => 'Token expired']);
        });

        $response = $this->get('/admin/marketplace/purchase/callback?token=bad-token&package=acme/test');

        $response->assertRedirect(route('vela.admin.marketplace.index'));
        $response->assertSessionHas('error');
    }

    public function test_callback_is_idempotent(): void
    {
        $this->loginAsAdmin();

        $composerName = 'acme/idempotent-' . uniqid();

        // Pre-create an existing installed package
        InstalledPackage::create([
            'vendor_name' => 'acme',
            'package_name' => 'idempotent-test',
            'composer_name' => $composerName,
            'version' => '1.0.0',
            'status' => InstalledPackage::STATUS_ACTIVE,
            'installed_at' => now(),
        ]);

        $this->mock(MarketplaceClient::class, function ($mock) use ($composerName) {
            $mock->shouldReceive('exchangeToken')->andReturn([
                'license_key' => 'vela_updated_key',
                'composer_name' => $composerName,
                'type' => 'onetime',
                'domain' => 'test.com',
                'dev_domain' => null,
                'expires_at' => null,
                'marketplace_purchase_id' => '456',
            ]);
        });

        $this->mock(LicenseCacheWriter::class, function ($mock) {
            $mock->shouldReceive('write')->andReturn(null);
        });

        $response = $this->get('/admin/marketplace/purchase/callback?token=abc&package=' . urlencode($composerName));

        $response->assertRedirect();

        // Only one installed package should exist for this composer_name
        $count = InstalledPackage::where('composer_name', $composerName)->count();
        $this->assertEquals(1, $count);
    }
}
