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
     * The default "User" role is what public self-registration hands out, so it
     * must not carry anything that exposes personal data or executes code.
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

    public function test_default_user_role_keeps_content_permissions(): void
    {
        $this->seedRoles();

        $titles = Role::find(2)->permissions->pluck('title')->all();

        foreach (['page_access', 'page_edit', 'article_access', 'article_edit', 'profile_password_edit'] as $allowed) {
            $this->assertContains($allowed, $titles, "User role should keep {$allowed}");
        }
    }
}
