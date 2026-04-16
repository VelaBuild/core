<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Tests\TestCase;

class RolesControllerTest extends TestCase
{
    public function test_index_lists_roles(): void
    {
        Permission::firstOrCreate(['title' => 'role_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/roles');
        $response->assertStatus(200);
    }

    public function test_store_creates_role(): void
    {
        Permission::firstOrCreate(['title' => 'role_create']);
        $this->loginAsAdmin();

        $title = 'Test Role ' . uniqid();

        $response = $this->post('/admin/roles', [
            'title' => $title,
            'permissions' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_roles', ['title' => $title]);
    }

    public function test_update_edits_role(): void
    {
        Permission::firstOrCreate(['title' => 'role_edit']);
        $this->loginAsAdmin();

        $role = Role::factory()->create(['title' => 'Old Role ' . uniqid()]);
        $newTitle = 'New Role ' . uniqid();

        $response = $this->put('/admin/roles/' . $role->id, [
            'title' => $newTitle,
            'permissions' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_roles', ['id' => $role->id, 'title' => $newTitle]);
    }

    public function test_destroy_deletes_role(): void
    {
        Permission::firstOrCreate(['title' => 'role_delete']);
        $this->loginAsAdmin();

        $role = Role::factory()->create();

        $response = $this->delete('/admin/roles/' . $role->id);

        $response->assertRedirect();
        $this->assertSoftDeleted('vela_roles', ['id' => $role->id]);
    }
}
