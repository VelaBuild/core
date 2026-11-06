<?php

namespace VelaBuild\Core\Tests\Feature\Marketplace;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Services\Marketplace\LicenseCacheWriter;
use VelaBuild\Core\Services\Marketplace\MarketplaceClient;
use VelaBuild\Core\Services\Marketplace\MarketplaceSettingsService;
use VelaBuild\Core\Services\Marketplace\PackageInstaller;
use VelaBuild\Core\Tests\TestCase;

class MarketplaceLicenseActivateTest extends TestCase
{
    use DatabaseTransactions;

    private function plugin(string $composerName): array
    {
        return [
            'slug' => 'store',
            'name' => 'Store',
            'composer_name' => $composerName,
            'price' => '99.00',
            'price_type' => 'onetime',
            'current_version' => '0.1.0',
            'description' => "# Vela Store\r\n\r\nThe **Ecommerce** platform <script>alert(1)</script>",
        ];
    }

    private function domain(): string
    {
        return app(MarketplaceSettingsService::class)->getDomain();
    }

    public function test_a_valid_key_installs_the_plugin_and_keeps_the_license(): void
    {
        $this->loginAsAdmin();
        $composerName = 'acme/store-' . uniqid();
        $domain = $this->domain();

        $this->mock(MarketplaceClient::class, function ($mock) use ($composerName, $domain) {
            $mock->shouldReceive('getPlugin')->with('store')->andReturn($this->plugin($composerName));
            $mock->shouldReceive('validateLicense')->once()->with('vela_granted', $domain)->andReturn([
                'valid' => true,
                'type' => 'onetime',
                'expires_at' => null,
                'domain' => $domain,
                'dev_domain' => null,
            ]);
            $mock->shouldReceive('registerSite')->once()->andReturn(true);
        });

        $this->mock(PackageInstaller::class, function ($mock) use ($composerName) {
            $mock->shouldReceive('install')->once()->with($composerName, 'vela_granted')->andReturn(['success' => true, 'output' => 'OK']);
            $mock->shouldReceive('ensureGitignoreHasAuthJson')->andReturn(null);
        });

        $this->mock(LicenseCacheWriter::class, fn ($mock) => $mock->shouldReceive('write')->andReturn(null));

        $response = $this->post('/admin/marketplace/store/activate', ['license_key' => '  vela_granted ']);

        $response->assertRedirect(route('vela.admin.packages.index'));
        $response->assertSessionHas('success');

        $package = InstalledPackage::where('composer_name', $composerName)->firstOrFail();
        $this->assertSame('0.1.0', $package->version);
        $license = $package->license;
        $this->assertNotNull($license);
        $this->assertSame('vela_granted', $license->license_key); // stored encrypted, read back through the cast
        $this->assertSame($domain, $license->domain);
        $this->assertSame('onetime', $license->type);
    }

    public function test_a_key_for_another_domain_is_refused_with_the_reason(): void
    {
        $this->loginAsAdmin();
        $composerName = 'acme/store-' . uniqid();

        $this->mock(MarketplaceClient::class, function ($mock) use ($composerName) {
            $mock->shouldReceive('getPlugin')->andReturn($this->plugin($composerName));
            $mock->shouldReceive('validateLicense')->andReturn(['valid' => false, 'reason' => 'domain_mismatch']);
        });
        $this->mock(PackageInstaller::class, fn ($mock) => $mock->shouldNotReceive('install'));

        $response = $this->post('/admin/marketplace/store/activate', ['license_key' => 'vela_other']);

        $response->assertRedirect(route('vela.admin.marketplace.show', 'store'));
        $response->assertSessionHas('error', fn ($m) => str_contains($m, 'different domain'));
        $this->assertDatabaseMissing('vela_installed_packages', ['composer_name' => $composerName]);
    }

    public function test_an_unknown_key_is_refused(): void
    {
        $this->loginAsAdmin();
        $composerName = 'acme/store-' . uniqid();

        $this->mock(MarketplaceClient::class, function ($mock) use ($composerName) {
            $mock->shouldReceive('getPlugin')->andReturn($this->plugin($composerName));
            $mock->shouldReceive('validateLicense')->andReturn(['valid' => false, 'reason' => 'invalid']);
        });
        $this->mock(PackageInstaller::class, fn ($mock) => $mock->shouldNotReceive('install'));

        $response = $this->post('/admin/marketplace/store/activate', ['license_key' => 'vela_nope']);

        $response->assertSessionHas('error', fn ($m) => str_contains($m, 'did not recognise'));
        $this->assertDatabaseMissing('vela_installed_packages', ['composer_name' => $composerName]);
    }

    public function test_a_key_the_marketplace_says_is_for_another_plugin_is_refused(): void
    {
        $this->loginAsAdmin();
        $composerName = 'acme/store-' . uniqid();

        $this->mock(MarketplaceClient::class, function ($mock) use ($composerName) {
            $mock->shouldReceive('getPlugin')->andReturn($this->plugin($composerName));
            $mock->shouldReceive('validateLicense')->andReturn(['valid' => true, 'composer_name' => 'acme/cheap-plugin']);
        });
        $this->mock(PackageInstaller::class, fn ($mock) => $mock->shouldNotReceive('install'));

        $response = $this->post('/admin/marketplace/store/activate', ['license_key' => 'vela_cheap']);

        $response->assertSessionHas('error', fn ($m) => str_contains($m, 'different plugin'));
        $this->assertDatabaseMissing('vela_installed_packages', ['composer_name' => $composerName]);
    }

    public function test_the_key_is_required(): void
    {
        $this->loginAsAdmin();

        $response = $this->post('/admin/marketplace/store/activate', ['license_key' => '']);

        $response->assertSessionHasErrors('license_key');
    }

    public function test_activating_requires_the_install_permission(): void
    {
        $user = VelaUser::factory()->create();
        $role = Role::create(['title' => 'BrowseOnly_' . uniqid()]);
        $user->roles()->attach($role);
        $this->actingAs($user, 'vela');

        $response = $this->post('/admin/marketplace/store/activate', ['license_key' => 'vela_x']);

        $response->assertStatus(403);
    }

    public function test_the_plugin_page_offers_the_key_form_sends_the_bare_domain_and_renders_markdown_safely(): void
    {
        $this->loginAsAdmin();
        $composerName = 'acme/store-' . uniqid();

        $this->mock(MarketplaceClient::class, function ($mock) use ($composerName) {
            $mock->shouldReceive('getPlugin')->with('store')->andReturn($this->plugin($composerName));
        });

        $response = $this->get('/admin/marketplace/store');

        $response->assertOk();
        $response->assertSee('Already have a license?');
        $response->assertSee(route('vela.admin.marketplace.activate', 'store'), false);
        $response->assertSee('domain=' . urlencode($this->domain()) . '&amp;', false);
        $response->assertDontSee('domain=http', false);
        $response->assertSee('<h1>Vela Store</h1>', false);
        $response->assertSee('<strong>Ecommerce</strong>', false);
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('# Vela Store', false);
    }
}
