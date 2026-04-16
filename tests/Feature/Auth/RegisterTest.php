<?php

namespace VelaBuild\Core\Tests\Feature\Auth;

use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Tests\TestCase;

class RegisterTest extends TestCase
{
    public function test_register_page_renders(): void
    {
        $response = $this->get('/vela/register');
        $response->assertStatus(200);
    }

    public function test_user_can_register(): void
    {
        $email = 'newuser_' . uniqid() . '@example.com';

        $response = $this->post('/vela/register', [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('vela.admin.home'));
        $this->assertDatabaseHas('vela_users', ['email' => $email]);
    }

    public function test_registration_validates_email(): void
    {
        $response = $this->post('/vela/register', [
            'name' => 'Test User',
            'email' => 'not-an-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors(['email']);
    }
}
