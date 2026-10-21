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

    public function test_registered_user_gets_only_the_guest_role(): void
    {
        $this->enableRegistration();

        // The default role has to exist for the created hook to attach it;
        // registration must then swap it for Guest. `id` is not fillable on
        // Role, hence the explicit assignment.
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

        $this->assertSame([Role::GUEST_TITLE], $user->roles()->pluck('title')->all());
    }

    /**
     * Widening the User role for editors must not widen what a stranger who
     * signs up gets.
     */
    public function test_widening_the_user_role_does_not_reach_self_registered_accounts(): void
    {
        $this->enableRegistration();
        (new \VelaBuild\Core\Database\Seeders\VelaPermissionsSeeder())->run();
        (new \VelaBuild\Core\Database\Seeders\VelaRolesSeeder())->run();

        Role::find(2)->permissions()->sync(\VelaBuild\Core\Models\Permission::pluck('id'));

        $this->post('/vela/register', [
            'name' => 'Stranger',
            'email' => 'stranger@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = VelaUser::where('email', 'stranger@example.com')->firstOrFail();

        $this->assertSame(['profile_password_edit'], $user->roles->flatMap->permissions->pluck('title')->all());
        $this->get('/admin/pages')->assertForbidden();
    }

    /**
     * Sites installed before the Guest role existed never ran the seeder for
     * it; the first sign-up makes it.
     */
    public function test_first_sign_up_on_an_older_site_makes_the_guest_role(): void
    {
        $this->enableRegistration();
        (new \VelaBuild\Core\Database\Seeders\VelaPermissionsSeeder())->run();

        $this->assertNull(Role::where('title', Role::GUEST_TITLE)->first());

        $this->post('/vela/register', [
            'name' => 'First',
            'email' => 'first@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $guest = Role::where('title', Role::GUEST_TITLE)->firstOrFail();
        $this->assertSame(['profile_password_edit'], $guest->permissions->pluck('title')->all());
        $this->assertSame([$guest->id], VelaUser::where('email', 'first@example.com')->firstOrFail()->roles->pluck('id')->all());
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

    public function test_the_admin_setting_turns_registration_on(): void
    {
        \VelaBuild\Core\Models\VelaConfig::updateOrCreate(['key' => 'registration_enabled'], ['value' => '1']);

        $this->get('/vela/register')->assertStatus(200);
        $this->get('/vela/login')->assertSee(route('vela.auth.register'));
    }

    public function test_the_admin_setting_turns_registration_off_whatever_the_env_says(): void
    {
        $this->enableRegistration();
        \VelaBuild\Core\Models\VelaConfig::updateOrCreate(['key' => 'registration_enabled'], ['value' => '0']);

        $this->get('/vela/register')->assertStatus(404);
        $this->get('/vela/login')->assertDontSee(route('vela.auth.register'));
    }

    public function test_the_visibility_settings_save_the_registration_switch(): void
    {
        (new \VelaBuild\Core\Database\Seeders\VelaPermissionsSeeder())->run();
        $admin = $this->signIn();
        $role = Role::firstOrCreate(['id' => 1], ['title' => 'Admin']);
        $role->permissions()->sync(\VelaBuild\Core\Models\Permission::pluck('id'));
        $admin->roles()->attach($role);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

        $this->get('/admin/settings/visibility')->assertOk()->assertSee('name="registration_enabled"', false);

        $this->post('/admin/settings/visibility', ['visibility_mode' => 'public', 'registration_enabled' => '1'])->assertRedirect();
        $this->assertTrue(vela_registration_enabled());

        $this->post('/admin/settings/visibility', ['visibility_mode' => 'public', 'registration_enabled' => '0'])->assertRedirect();
        $this->assertFalse(vela_registration_enabled());
    }

    public function test_the_ai_assistant_cannot_turn_registration_on(): void
    {
        $result = app(\VelaBuild\Core\Services\AiChat\Tools\UpdateSiteConfigTool::class)
            ->execute(['key' => 'registration_enabled', 'value' => '1', 'create_new' => true]);

        $this->assertArrayHasKey('error', $result);
        $this->assertFalse(vela_registration_enabled());
    }
}
