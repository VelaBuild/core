<?php

namespace VelaBuild\Core\Tests\Feature\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Tests\TestCase;

class ToolsIndexTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_view_tools_index(): void
    {
        $this->loginAsAdmin();
        $response = $this->get(route('vela.admin.tools.index'));
        $response->assertStatus(200);
        $response->assertSee('Tools');
    }

    public function test_user_with_tools_access_can_view_index(): void
    {
        $user = $this->loginAsUser();
        $permission = Permission::firstOrCreate(['title' => 'tools_access']);
        $user->roles->first()->permissions()->attach($permission);

        $response = $this->get(route('vela.admin.tools.index'));
        $response->assertStatus(200);
    }

    public function test_user_without_permission_gets_403(): void
    {
        $this->loginAsUser();
        $response = $this->get(route('vela.admin.tools.index'));
        $response->assertStatus(403);
    }

    public function test_index_shows_all_registered_tools(): void
    {
        $this->loginAsAdmin();
        $response = $this->get(route('vela.admin.tools.index'));
        $response->assertSee('Google Analytics');
        $response->assertSee('Cloudflare');
        $response->assertSee('Email Send Tester');
        $response->assertSee('Reviews');
    }
}
