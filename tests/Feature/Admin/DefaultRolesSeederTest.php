<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Database\Seeders\VelaPermissionsSeeder;
use VelaBuild\Core\Database\Seeders\VelaRolesSeeder;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Tests\TestCase;

class DefaultRolesSeederTest extends TestCase
{
    public function test_permissions_seeder_creates_all_permissions(): void
    {
        // Clean slate for this test
        \DB::table('vela_permission_role')->delete();
        \DB::table('vela_permissions')->delete();

        $this->seed(VelaPermissionsSeeder::class);

        $this->assertGreaterThanOrEqual(49, Permission::count());
        $this->assertNotNull(Permission::where('title', 'user_management_access')->first());
        $this->assertNotNull(Permission::where('title', 'profile_password_edit')->first());
    }

    public function test_permissions_seeder_is_idempotent(): void
    {
        // Clean slate
        \DB::table('vela_permission_role')->delete();
        \DB::table('vela_permissions')->delete();

        $this->seed(VelaPermissionsSeeder::class);
        $countAfterFirst = Permission::count();

        $this->seed(VelaPermissionsSeeder::class);
        $countAfterSecond = Permission::count();

        $this->assertEquals($countAfterFirst, $countAfterSecond, 'Running permissions seeder twice should not create duplicates');
    }

    public function test_roles_seeder_creates_admin_and_user_roles(): void
    {
        // Clean slate
        \DB::table('vela_permission_role')->delete();
        \DB::table('vela_role_user')->delete();
        \DB::table('vela_roles')->delete();
        \DB::table('vela_permissions')->delete();

        $this->seed(VelaPermissionsSeeder::class);
        $this->seed(VelaRolesSeeder::class);

        $admin = Role::find(1);
        $user = Role::find(2);

        $this->assertNotNull($admin);
        $this->assertEquals('Admin', $admin->title);
        $this->assertNotNull($user);
        $this->assertEquals('User', $user->title);
    }

    public function test_admin_role_gets_all_permissions(): void
    {
        \DB::table('vela_permission_role')->delete();
        \DB::table('vela_role_user')->delete();
        \DB::table('vela_roles')->delete();
        \DB::table('vela_permissions')->delete();

        $this->seed(VelaPermissionsSeeder::class);
        $this->seed(VelaRolesSeeder::class);

        $admin = Role::find(1);
        $totalPermissions = Permission::count();

        $this->assertEquals($totalPermissions, $admin->permissions()->count(), 'Admin should have all permissions');
    }

    public function test_user_role_excludes_management_permissions(): void
    {
        \DB::table('vela_permission_role')->delete();
        \DB::table('vela_role_user')->delete();
        \DB::table('vela_roles')->delete();
        \DB::table('vela_permissions')->delete();

        $this->seed(VelaPermissionsSeeder::class);
        $this->seed(VelaRolesSeeder::class);

        $user = Role::find(2);
        $userPermTitles = $user->permissions()->pluck('title')->toArray();

        $this->assertNotContains('user_management_access', $userPermTitles);
        $this->assertNotContains('permission_access', $userPermTitles);
        $this->assertNotContains('role_access', $userPermTitles);
        $this->assertNotContains('config_access', $userPermTitles);
        $this->assertContains('article_access', $userPermTitles);
        $this->assertContains('page_access', $userPermTitles);
    }

    public function test_admin_gets_new_permissions_on_rerun(): void
    {
        \DB::table('vela_permission_role')->delete();
        \DB::table('vela_role_user')->delete();
        \DB::table('vela_roles')->delete();
        \DB::table('vela_permissions')->delete();

        $this->seed(VelaPermissionsSeeder::class);
        $this->seed(VelaRolesSeeder::class);

        // Simulate a new permission being added
        $newPerm = Permission::firstOrCreate(['title' => 'test_new_permission_' . uniqid()]);

        // Re-run roles seeder
        $this->seed(VelaRolesSeeder::class);

        $admin = Role::find(1);
        $this->assertTrue(
            $admin->permissions()->where('vela_permissions.id', $newPerm->id)->exists(),
            'Admin should get newly added permissions on re-run'
        );
    }

    public function test_user_role_permissions_not_overwritten_on_rerun(): void
    {
        \DB::table('vela_permission_role')->delete();
        \DB::table('vela_role_user')->delete();
        \DB::table('vela_roles')->delete();
        \DB::table('vela_permissions')->delete();

        $this->seed(VelaPermissionsSeeder::class);
        $this->seed(VelaRolesSeeder::class);

        $user = Role::find(2);
        $initialCount = $user->permissions()->count();

        // Manually remove a permission from User role (simulating admin action)
        $removedPerm = $user->permissions()->first();
        $user->permissions()->detach($removedPerm->id);

        // Re-run seeder
        $this->seed(VelaRolesSeeder::class);

        // The removed permission should NOT be re-added
        $this->assertEquals(
            $initialCount - 1,
            $user->permissions()->count(),
            'User role permissions should not be overwritten on re-run'
        );
    }

    public function test_roles_seeder_is_idempotent(): void
    {
        \DB::table('vela_permission_role')->delete();
        \DB::table('vela_role_user')->delete();
        \DB::table('vela_roles')->delete();
        \DB::table('vela_permissions')->delete();

        $this->seed(VelaPermissionsSeeder::class);
        $this->seed(VelaRolesSeeder::class);

        $adminPermCount1 = Role::find(1)->permissions()->count();
        $userPermCount1 = Role::find(2)->permissions()->count();

        $this->seed(VelaRolesSeeder::class);

        $adminPermCount2 = Role::find(1)->permissions()->count();
        $userPermCount2 = Role::find(2)->permissions()->count();

        $this->assertEquals($adminPermCount1, $adminPermCount2, 'Admin permissions should not change on re-run');
        $this->assertEquals($userPermCount1, $userPermCount2, 'User permissions should not change on re-run');
    }
}
