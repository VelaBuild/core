<?php

namespace VelaBuild\Core\Tests\Feature\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Tests\TestCase;

class ToolsPermissionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tools_access_can_view_email_tester(): void
    {
        $user = $this->loginAsUser();
        $permission = Permission::firstOrCreate(['title' => 'tools_access']);
        $user->roles->first()->permissions()->attach($permission);

        $response = $this->get(route('vela.admin.tools.email-tester'));
        $response->assertStatus(200);
    }

    public function test_tools_access_cannot_view_cloudflare(): void
    {
        $user = $this->loginAsUser();
        $permission = Permission::firstOrCreate(['title' => 'tools_access']);
        $user->roles->first()->permissions()->attach($permission);

        $response = $this->get(route('vela.admin.tools.cloudflare'));
        $response->assertStatus(403);
    }

    public function test_admin_tools_access_can_view_cloudflare(): void
    {
        $user = $this->loginAsUser();
        $permission = Permission::firstOrCreate(['title' => 'admin_tools_access']);
        $user->roles->first()->permissions()->attach($permission);

        $response = $this->get(route('vela.admin.tools.cloudflare'));
        $response->assertStatus(200);
    }

    public function test_admin_tools_access_can_save_ga_config(): void
    {
        $user = $this->loginAsUser();
        $permission = Permission::firstOrCreate(['title' => 'admin_tools_access']);
        $user->roles->first()->permissions()->attach($permission);

        $response = $this->post(route('vela.admin.tools.google-analytics.config'), [
            'ga_measurement_id' => 'G-TESTID123',
        ]);
        $response->assertRedirect();
    }

    public function test_tools_access_cannot_save_ga_config(): void
    {
        $user = $this->loginAsUser();
        $permission = Permission::firstOrCreate(['title' => 'tools_access']);
        $user->roles->first()->permissions()->attach($permission);

        $response = $this->post(route('vela.admin.tools.google-analytics.config'), [
            'ga_measurement_id' => 'G-TESTID123',
        ]);
        $response->assertStatus(403);
    }
}
