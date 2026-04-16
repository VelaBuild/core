<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class AppSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_app_settings_page_loads()
    {
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsAdmin();
        $response = $this->get('/admin/settings/app');
        $response->assertStatus(200);
    }

    public function test_app_settings_requires_permission()
    {
        $this->loginAsUser();
        $response = $this->get('/admin/settings/app');
        $response->assertStatus(403);
    }

    public function test_app_settings_save()
    {
        Permission::firstOrCreate(['title' => 'config_edit']);
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsAdmin();

        $response = $this->post('/admin/settings/app', [
            'app_ios_url' => 'https://apps.apple.com/app/test/id123',
            'app_android_url' => 'https://play.google.com/store/apps/details?id=com.test',
            'app_name' => 'Test App',
            'app_custom_scheme' => 'testapp://',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_configs', ['key' => 'app_ios_url', 'value' => 'https://apps.apple.com/app/test/id123']);
        $this->assertDatabaseHas('vela_configs', ['key' => 'app_android_url', 'value' => 'https://play.google.com/store/apps/details?id=com.test']);
        $this->assertDatabaseHas('vela_configs', ['key' => 'app_name', 'value' => 'Test App']);
        $this->assertDatabaseHas('vela_configs', ['key' => 'app_custom_scheme', 'value' => 'testapp://']);
    }

    public function test_app_settings_validation_rejects_invalid_url()
    {
        Permission::firstOrCreate(['title' => 'config_edit']);
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsAdmin();

        $response = $this->post('/admin/settings/app', [
            'app_ios_url' => 'not-a-url',
        ]);

        $response->assertSessionHasErrors('app_ios_url');
    }

    public function test_app_settings_card_visible_on_index()
    {
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/settings');
        $response->assertStatus(200);
        $response->assertSee('Native App');
    }

    public function test_app_settings_save_requires_config_edit()
    {
        Permission::firstOrCreate(['title' => 'config_access']);
        $this->loginAsUser();

        $response = $this->post('/admin/settings/app', [
            'app_name' => 'Test',
        ]);

        $response->assertStatus(403);
    }
}
