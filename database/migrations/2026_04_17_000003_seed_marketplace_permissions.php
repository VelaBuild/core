<?php

use Illuminate\Database\Migrations\Migration;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'marketplace_browse',
            'marketplace_install',
            'marketplace_manage',
        ];

        foreach ($permissions as $title) {
            Permission::firstOrCreate(['title' => $title]);
        }

        // Attach new marketplace permissions to Admin role (without removing existing ones)
        $adminRole = Role::find(1);
        if ($adminRole) {
            $newPermissions = Permission::whereIn('title', $permissions)->pluck('id');
            $adminRole->permissions()->syncWithoutDetaching($newPermissions);
        }

        // Attach only browse to User role
        $userRole = Role::find(2);
        if ($userRole) {
            $browsePermission = Permission::where('title', 'marketplace_browse')->first();
            if ($browsePermission) {
                $userRole->permissions()->syncWithoutDetaching([$browsePermission->id]);
            }
        }
    }

    public function down(): void
    {
        $permissions = Permission::whereIn('title', [
            'marketplace_browse',
            'marketplace_install',
            'marketplace_manage',
        ])->get();

        foreach ($permissions as $permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
