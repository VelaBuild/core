<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ConfigControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_settings_index_renders(): void
    {
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/settings');
        $response->assertStatus(200);
        $response->assertSee('General');
        $response->assertSee('Appearance');
        $response->assertSee('Progressive Web App');
    }

    public function test_settings_group_page_renders(): void
    {
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsAdmin();

        foreach (['general', 'appearance', 'pwa'] as $group) {
            $response = $this->get("/admin/settings/{$group}");
            $response->assertStatus(200);
        }
    }

    public function test_settings_invalid_group_returns_404(): void
    {
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/settings/nonexistent');
        $response->assertStatus(404);
    }

    public function test_general_settings_save(): void
    {
        Permission::firstOrCreate(['title' => 'config_edit']);
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsAdmin();

        $response = $this->post('/admin/settings/general', [
            'site_name' => 'Test Site PWA',
            'site_niche' => 'Testing',
            'site_description' => 'A test site',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_configs', ['key' => 'site_name', 'value' => 'Test Site PWA']);
        $this->assertDatabaseHas('vela_configs', ['key' => 'site_niche', 'value' => 'Testing']);
    }

    public function test_pwa_settings_save(): void
    {
        Permission::firstOrCreate(['title' => 'config_edit']);
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsAdmin();

        $response = $this->post('/admin/settings/pwa', [
            'pwa_enabled' => '1',
            'pwa_name' => 'My PWA App',
            'pwa_short_name' => 'MyApp',
            'pwa_display' => 'standalone',
            'pwa_theme_color' => '#ff0000',
            'pwa_background_color' => '#ffffff',
            'pwa_offline_enabled' => '1',
            'pwa_precache_urls' => '/,/posts',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_configs', ['key' => 'pwa_enabled', 'value' => '1']);
        $this->assertDatabaseHas('vela_configs', ['key' => 'pwa_name', 'value' => 'My PWA App']);
        $this->assertDatabaseHas('vela_configs', ['key' => 'pwa_theme_color', 'value' => '#ff0000']);
    }

    public function test_pwa_settings_save_increments_sw_version(): void
    {
        Permission::firstOrCreate(['title' => 'config_edit']);
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsAdmin();

        VelaConfig::updateOrCreate(['key' => 'sw_version'], ['value' => '5']);

        $this->post('/admin/settings/pwa', [
            'pwa_enabled' => '1',
            'pwa_name' => 'Test',
        ]);

        $newVersion = VelaConfig::where('key', 'sw_version')->value('value');
        $this->assertGreaterThan(5, (int) $newVersion);
    }

    public function test_settings_requires_config_access_permission(): void
    {
        $this->loginAsUser();

        $response = $this->get('/admin/settings');
        $response->assertStatus(403);
    }

    public function test_settings_save_requires_config_edit_permission(): void
    {
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsUser();

        $response = $this->post('/admin/settings/general', [
            'site_name' => 'Should Fail',
        ]);
        $response->assertStatus(403);
    }

    public function test_old_configs_route_redirects_to_settings(): void
    {
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/configs');
        $response->assertRedirect(route('vela.admin.settings.index'));
    }

    public function test_existing_css_configs_visible_in_appearance(): void
    {
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsAdmin();

        VelaConfig::updateOrCreate(['key' => 'css_--primary'], ['value' => '#123456']);

        $response = $this->get('/admin/settings/appearance');
        $response->assertStatus(200);
        $response->assertSee('#123456');
    }
}
