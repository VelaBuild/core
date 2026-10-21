<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Database\Seeders\VelaPermissionsSeeder;
use VelaBuild\Core\Database\Seeders\VelaRolesSeeder;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Tests\PackageTestCase;

class VelaRolesSeederTest extends PackageTestCase
{
    protected function seedRoles(): void
    {
        (new VelaPermissionsSeeder())->run();
        (new VelaRolesSeeder())->run();
    }

    public function test_admin_role_gets_every_permission(): void
    {
        $this->seedRoles();

        $admin = Role::find(1);

        $this->assertNotNull($admin);
        $this->assertContains('user_management_access', $admin->permissions->pluck('title')->all());
        $this->assertContains('marketplace_install', $admin->permissions->pluck('title')->all());
    }

    /**
     * The default "User" role is what every admin-made account starts with, so
     * it must not carry anything that exposes personal data or executes code.
     */
    public function test_default_user_role_excludes_sensitive_permissions(): void
    {
        $this->seedRoles();

        $titles = Role::find(2)->permissions->pluck('title')->all();

        foreach ([
            'user_management_access',
            'role_access',
            'config_access',
            'form_submission_access',
            'form_submission_show',
            'form_submission_delete',
            'marketplace_install',
            'marketplace_manage',
            'admin_tools_access',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $titles, "User role must not have {$forbidden}");
        }
    }

    /**
     * It holds the dashboard (which needs no permission) and the account's own
     * profile, and nothing else. Everything more is given by an administrator.
     */
    public function test_default_user_role_has_only_its_own_profile(): void
    {
        $this->seedRoles();

        $this->assertSame(
            ['profile_password_edit'],
            Role::find(2)->permissions->pluck('title')->sort()->values()->all()
        );
    }

    public function test_default_user_role_is_left_alone_once_an_admin_has_changed_it(): void
    {
        $this->seedRoles();

        $user = Role::find(2);
        $user->permissions()->syncWithoutDetaching(
            \VelaBuild\Core\Models\Permission::where('title', 'page_edit')->pluck('id')
        );

        (new VelaRolesSeeder())->run();

        $this->assertContains('page_edit', $user->fresh()->permissions->pluck('title')->all());
    }

    /**
     * Accounts that sign themselves up get Guest, never User, and Guest holds
     * only the account's own profile.
     */
    public function test_seeder_makes_a_guest_role_with_only_its_own_profile(): void
    {
        $this->seedRoles();

        $guest = Role::where('title', Role::GUEST_TITLE)->firstOrFail();

        $this->assertNotContains((int) $guest->id, [1, 2]);
        $this->assertSame(['profile_password_edit'], $guest->permissions->pluck('title')->all());
    }

    public function test_guest_role_is_left_alone_once_an_admin_has_changed_it(): void
    {
        $this->seedRoles();

        $guest = Role::guest();
        $guest->permissions()->detach();

        (new VelaRolesSeeder())->run();

        $this->assertSame(1, Role::where('title', Role::GUEST_TITLE)->count());
        $this->assertSame([], $guest->fresh()->permissions->pluck('title')->all());
    }
}
