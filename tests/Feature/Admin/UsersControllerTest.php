<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Tests\TestCase;

class UsersControllerTest extends TestCase
{
    public function test_index_renders(): void
    {
        Permission::firstOrCreate(['title' => 'user_access']);
        $this->loginAsAdmin();

        $response = $this->get('/admin/users');
        $response->assertStatus(200);
    }

    public function test_store_creates_user(): void
    {
        Permission::firstOrCreate(['title' => 'user_create']);
        $this->loginAsAdmin();

        $email = 'testuser_' . uniqid() . '@example.com';

        $response = $this->post('/admin/users', [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_users', ['email' => $email]);
    }

    public function test_update_user(): void
    {
        Permission::firstOrCreate(['title' => 'user_edit']);
        $this->loginAsAdmin();

        $user = VelaUser::factory()->create(['name' => 'Old Name']);

        $response = $this->put('/admin/users/' . $user->id, [
            'name' => 'New Name',
            'email' => $user->email,
            'roles' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vela_users', ['id' => $user->id, 'name' => 'New Name']);
    }

    public function test_destroy_user(): void
    {
        Permission::firstOrCreate(['title' => 'user_delete']);
        $this->loginAsAdmin();

        $user = VelaUser::factory()->create();

        $response = $this->delete('/admin/users/' . $user->id);

        $response->assertRedirect();
        $this->assertSoftDeleted('vela_users', ['id' => $user->id]);
    }
}
