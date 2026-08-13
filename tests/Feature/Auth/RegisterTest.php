<?php

namespace VelaBuild\Core\Tests\Feature\Auth;

use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Tests\PackageTestCase;

class RegisterTest extends PackageTestCase
{
    protected function enableRegistration(): void
    {
        config()->set('vela.registration_enabled', true);
    }

    public function test_register_page_is_not_available_by_default(): void
    {
        $response = $this->get('/vela/register');
        $response->assertStatus(404);
    }

    public function test_registration_is_rejected_by_default(): void
    {
        $email = 'newuser_' . uniqid() . '@example.com';

        $response = $this->post('/vela/register', [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('vela_users', ['email' => $email]);
    }

    public function test_login_page_hides_register_link_by_default(): void
    {
        $response = $this->get('/vela/login');

        $response->assertStatus(200);
        $response->assertDontSee(route('vela.auth.register'));
    }

    public function test_login_page_shows_register_link_when_enabled(): void
    {
        $this->enableRegistration();

        $response = $this->get('/vela/login');

        $response->assertStatus(200);
        $response->assertSee(route('vela.auth.register'));
    }

    public function test_register_page_renders_when_enabled(): void
    {
        $this->enableRegistration();

        $response = $this->get('/vela/register');
        $response->assertStatus(200);
    }

    public function test_user_can_register_when_enabled(): void
    {
        $this->enableRegistration();

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

    public function test_registered_user_gets_the_default_role_exactly_once(): void
    {
        $this->enableRegistration();

        // roles() joins vela_roles, so the default role has to actually exist.
        // `id` is not fillable on Role, hence the explicit assignment.
        $role = new Role(['title' => 'User']);
        $role->id = (int) config('vela.registration_default_role');
        $role->save();

        $email = 'newuser_' . uniqid() . '@example.com';

        $this->post('/vela/register', [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = VelaUser::where('email', $email)->firstOrFail();

        $this->assertSame(1, $user->roles()->count());
        $this->assertSame(
            (int) config('vela.registration_default_role'),
            (int) $user->roles()->first()->id
        );
    }

    public function test_registration_validates_email(): void
    {
        $this->enableRegistration();

        $response = $this->post('/vela/register', [
            'name' => 'Test User',
            'email' => 'not-an-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors(['email']);
    }
}
