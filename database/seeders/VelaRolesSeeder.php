<?php

namespace VelaBuild\Core\Database\Seeders;

use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use Illuminate\Database\Seeder;

class VelaRolesSeeder extends Seeder
{
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

        // User: only assign default permissions if user role has NO permissions yet (first run)
        // This preserves any manual changes admins have made to the User role
        if ($user->permissions()->count() === 0) {
            $userExcluded = [
                'user_management_access',
                'permission_access', 'permission_create', 'permission_edit', 'permission_show', 'permission_delete',
                'role_access', 'role_create', 'role_edit', 'role_show', 'role_delete',
                'user_create', 'user_edit', 'user_delete',
                'config_access', 'config_create', 'config_edit', 'config_show', 'config_delete',
                'ai_chat_template_edit', 'ai_chat_config_manage',
            ];

            $userPermissions = Permission::whereNotIn('title', $userExcluded)->pluck('id')->toArray();
            $user->permissions()->sync($userPermissions);
        }
    }
}
