<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\Role;

/**
 * Defines a gate for every permission, from the roles that hold it.
 *
 * These definitions used to live inside the VelaAuthGates middleware, which
 * only runs during an HTTP request. Anything working outside one — a console
 * command, a queued job — therefore ran with no gates defined at all, and
 * every Gate::allows() answered false. The design builder was the visible
 * casualty: ChatToolRegistry hands out tools by gate, so from the command
 * line it was given only the read-only ones and could never build anything.
 */
class PermissionGates
{
    public function register(): void
    {
        // Gates are global to the process, and the set never changes within
        // one; defining them a second time would just repeat the queries.
        if (Gate::has('config_edit')) {
            return;
        }

        $rolesByPermission = [];

        foreach (Role::with('permissions')->get() as $role) {
            foreach ($role->permissions as $permission) {
                $rolesByPermission[$permission->title][] = $role->id;
            }
        }

        foreach ($rolesByPermission as $title => $roleIds) {
            Gate::define($title, function ($user) use ($roleIds) {
                return count(array_intersect($user->roles->pluck('id')->toArray(), $roleIds)) > 0;
            });
        }
    }
}
