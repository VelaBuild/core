<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
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
            'roles' => [Role::firstOrCreate(['id' => 1], ['title' => 'Admin'])->id],
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
            'roles' => [Role::firstOrCreate(['id' => 1], ['title' => 'Admin'])->id],
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

    public function test_mass_destroy_deletes_every_selected_user(): void
    {
        $this->loginAsAdmin();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

        $first = VelaUser::factory()->create();
        $second = VelaUser::factory()->create();
        $kept = VelaUser::factory()->create();

        // As the list page sends them: strings, newest first.
        $this->post('/admin/users/destroy', ['ids' => [(string) $second->id, (string) $first->id], '_method' => 'DELETE'])
            ->assertNoContent();

        $this->assertSoftDeleted('vela_users', ['id' => $first->id]);
        $this->assertSoftDeleted('vela_users', ['id' => $second->id]);
        $this->assertNotSoftDeleted('vela_users', ['id' => $kept->id]);
    }

    public function test_mass_destroy_deletes_none_when_one_fails(): void
    {
        $this->loginAsAdmin();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

        $first = VelaUser::factory()->create();
        $second = VelaUser::factory()->create();

        VelaUser::deleting(function (VelaUser $user) use ($second) {
            if ($user->id === $second->id) {
                throw new \RuntimeException('database went away');
            }
        });

        $this->post('/admin/users/destroy', ['ids' => [$first->id, $second->id], '_method' => 'DELETE'])
            ->assertStatus(500);

        $this->assertNotSoftDeleted('vela_users', ['id' => $first->id]);
        $this->assertNotSoftDeleted('vela_users', ['id' => $second->id]);
    }
}
