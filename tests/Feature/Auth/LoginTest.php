<?php

namespace VelaBuild\Core\Tests\Feature\Auth;

use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Support\Facades\Hash;

class LoginTest extends TestCase
{
    public function test_login_page_renders(): void
    {
        $response = $this->get('/vela/login');
        $response->assertStatus(200);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = VelaUser::factory()->create([
            'password' => Hash::make('password123'),
            'two_factor' => false,
        ]);

        $response = $this->post('/vela/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('vela.admin.home'));
        $this->assertAuthenticatedAs($user, 'vela');
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $user = VelaUser::factory()->create([
            'password' => Hash::make('correctpassword'),
        ]);

        $response = $this->post('/vela/login', [
            'email' => $user->email,
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors();
        $this->assertGuest('vela');
    }

    public function test_user_can_logout(): void
    {
        $user = VelaUser::factory()->create();
        $this->actingAs($user, 'vela');

        $response = $this->post('/vela/logout');

        $response->assertRedirect(route('vela.auth.login'));
        $this->assertGuest('vela');
    }
}
