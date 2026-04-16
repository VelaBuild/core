<?php

namespace VelaBuild\Core\Tests;

use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\CreatesApplication;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication, DatabaseTransactions;

    protected function loginAsAdmin(): VelaUser
    {
        $user = VelaUser::factory()->create();
        $adminRole = Role::firstOrCreate(['id' => 1], ['title' => 'Admin']);
        $permissions = Permission::all();
        $adminRole->permissions()->sync($permissions->pluck('id'));
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
