<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Tests\TestCase;

class PermissionsControllerTest extends TestCase
{
    public function test_index_lists_permissions(): void
    {
        Permission::firstOrCreate(['title' => 'permission_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/permissions');
        $response->assertStatus(200);
    }

    public function test_store_creates_permission(): void
    {
        Permission::firstOrCreate(['title' => 'permission_create']);
        $this->loginAsAdmin();

        $title = 'test_permission_' . uniqid();

        $response = $this->post('/admin/permissions', [
            'title' => $title,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_permissions', ['title' => $title]);
    }

    public function test_update_edits_permission(): void
    {
        Permission::firstOrCreate(['title' => 'permission_edit']);
        $this->loginAsAdmin();

        $permission = Permission::factory()->create(['title' => 'old_title_' . uniqid()]);
        $newTitle = 'new_title_' . uniqid();

        $response = $this->put('/admin/permissions/' . $permission->id, [
            'title' => $newTitle,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_permissions', ['id' => $permission->id, 'title' => $newTitle]);
    }

    public function test_destroy_deletes_permission(): void
    {
        Permission::firstOrCreate(['title' => 'permission_delete']);
        $this->loginAsAdmin();

        $permission = Permission::factory()->create();

        $response = $this->delete('/admin/permissions/' . $permission->id);

        $response->assertRedirect();
        $this->assertSoftDeleted('vela_permissions', ['id' => $permission->id]);
    }
}
