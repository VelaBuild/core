<?php

namespace VelaBuild\Core\Tests;

use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaUser;

/**
 * The base the package's older tests extend.
 *
 * It used to pull in `Tests\CreatesApplication`, a trait a host application
 * supplies — and one that has never existed in this repository: the import is
 * there in the first commit. So none of the eighty-four test files extending
 * this class has ever run, not once. Not a suite that broke and went unnoticed,
 * a suite that was moved here out of an application and never executed again.
 *
 * What that cost is easy to see in what they cover: the login and two-factor
 * tests, roles and permissions, the marketplace webhook and purchase callback,
 * the licence manager, the page controller, the static site generator. And,
 * exactly, AppearanceThemePickerTest and ConfigControllerTest — the screen
 * whose colour pickers turned out never to have worked on any theme Vela
 * ships, because a groupBy reindexed every field name on the form.
 *
 * Standing them up needs an application, which PackageTestCase already builds
 * through Testbench. This class is now that, plus the two helpers these tests
 * were written against.
 */
abstract class TestCase extends PackageTestCase
{
    /**
     * A user holding every permission there is.
     *
     * Roles are addressed by id because the seeder's admin role is id 1 and
     * some tests assert against that; firstOrCreate keeps a test that seeds
     * first from colliding with one that does not.
     */
    protected function loginAsAdmin(): VelaUser
    {
        // Without this the table is empty, so "every permission there is" is
        // none of them and the admin is refused by every gate in the panel —
        // which reads as a broken controller rather than an unseeded fixture.
        // Cheap enough to do per test: it is a few dozen rows in memory.
        $this->seed(\VelaBuild\Core\Database\Seeders\VelaPermissionsSeeder::class);

        $user = VelaUser::factory()->create();
        $adminRole = Role::firstOrCreate(['id' => 1], ['title' => 'Admin']);
        $adminRole->permissions()->sync(Permission::all()->pluck('id'));
        $user->roles()->attach($adminRole);
        $this->actingAs($user, 'vela');

        return $user;
    }

    protected function loginAsUser(): VelaUser
    {
        $user = VelaUser::factory()->create();
        $userRole = Role::firstOrCreate(['id' => 2], ['title' => 'User']);
        $user->roles()->attach($userRole);
        $this->actingAs($user, 'vela');

        return $user;
    }
}
