<?php

namespace VelaBuild\Core\Tests\Feature\Marketplace;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Services\Marketplace\MarketplaceClient;
use VelaBuild\Core\Tests\TestCase;

class MarketplaceBrowseControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_index_requires_marketplace_browse_permission(): void
    {
        $user = VelaUser::factory()->create();
        $role = Role::create(['title' => 'NoPerms_' . uniqid()]);
        $user->roles()->attach($role);
        $this->actingAs($user, 'vela');

        $response = $this->get('/admin/marketplace');

        $response->assertStatus(403);
    }

    public function test_index_displays_marketplace_page(): void
    {
        $this->loginAsAdmin();

        $this->mock(MarketplaceClient::class, function ($mock) {
            $mock->shouldReceive('getCatalog')->andReturn([
                ['slug' => 'test-plugin', 'name' => 'Test Plugin', 'composer_name' => 'acme/test-plugin', 'price_type' => 'free', 'price' => 0],
            ]);
        });

        $response = $this->get('/admin/marketplace');

        $response->assertStatus(200);
        $response->assertViewIs('vela::admin.marketplace.index');
    }

    public function test_search_returns_json(): void
    {
        $this->loginAsAdmin();

        $this->mock(MarketplaceClient::class, function ($mock) {
            $mock->shouldReceive('getCatalog')->with(['search' => 'test'])->andReturn([
                ['slug' => 'test-plugin', 'name' => 'Test Plugin', 'composer_name' => 'acme/test-plugin'],
            ]);
        });

        $response = $this->getJson('/admin/marketplace/search?search=test');

        $response->assertStatus(200);
        $response->assertJsonStructure([['slug', 'name', 'composer_name']]);
    }

    public function test_show_displays_plugin_detail(): void
    {
        $this->loginAsAdmin();

        $this->mock(MarketplaceClient::class, function ($mock) {
            $mock->shouldReceive('getPlugin')->with('test')->andReturn([
                'slug' => 'test',
                'composer_name' => 'acme/test',
                'name' => 'Test',
            ]);
        });

        $response = $this->get('/admin/marketplace/test');

        $response->assertStatus(200);
        $response->assertViewIs('vela::admin.marketplace.show');
    }

    public function test_show_returns_404_when_plugin_not_found(): void
    {
        $this->loginAsAdmin();

        $this->mock(MarketplaceClient::class, function ($mock) {
            $mock->shouldReceive('getPlugin')->with('nonexistent')->andReturn(null);
        });

        $response = $this->get('/admin/marketplace/nonexistent');

        $response->assertStatus(404);
    }
}
