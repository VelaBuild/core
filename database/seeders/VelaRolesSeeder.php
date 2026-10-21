<?php

namespace VelaBuild\Core\Database\Seeders;

use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use Illuminate\Database\Seeder;

class VelaRolesSeeder extends Seeder
{
    /**
     * Everything the default User role starts with.
     */
    public const USER_ROLE_PERMISSIONS = [
        'profile_password_edit',
    ];

    public function run(): void
    {
        $admin = Role::firstOrCreate(['id' => 1], ['title' => 'Admin']);
        $user  = Role::firstOrCreate(['id' => 2], ['title' => 'User']);

        // Admin: attach any permissions not already assigned (won't re-add removed ones
        // because we only add NEW permissions that don't exist in the pivot yet)
        $existingAdminPermIds = $admin->permissions()->pluck('vela_permissions.id')->toArray();
        $allPermIds = Permission::pluck('id')->toArray();
        $newPermIds = array_diff($allPermIds, $existingAdminPermIds);
        if (!empty($newPermIds)) {
            $admin->permissions()->attach($newPermIds);
        }

        // User: the role every account an admin makes gets. It starts with
        // the least an account can usefully have: the dashboard (which needs
        // no permission) and its own profile. Anything more — editing pages,
        // posts, media — is given by an administrator, on this role or another.
        //
        // Only seeded when the role is first made, so changes admins make to
        // it stand — including taking everything away.
        if ($user->wasRecentlyCreated) {
            $user->permissions()->sync(
                Permission::whereIn('title', self::USER_ROLE_PERMISSIONS)->pluck('id')->toArray()
            );
        }

        // Guest: what accounts that sign themselves up get, and nothing else.
        Role::guest();
    }
}
