<?php

namespace VelaBuild\Core\Http\Middleware;

use VelaBuild\Core\Models\Role;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class VelaAuthGates
{
    public function handle($request, Closure $next)
    {
        $velaUser = auth('vela')->user();

        if (!$velaUser) {
            return $next($request);
        }

        // Set the default guard to 'vela' so Gate checks resolve the correct user
        Auth::shouldUse('vela');

        $roles = Role::with('permissions')->get();
        $permissionsArray = [];

        foreach ($roles as $role) {
            foreach ($role->permissions as $permissions) {
                $permissionsArray[$permissions->title][] = $role->id;
            }
        }

        foreach ($permissionsArray as $title => $roles) {
            Gate::define($title, function ($user) use ($roles) {
                return count(array_intersect($user->roles->pluck('id')->toArray(), $roles)) > 0;
            });
        }

        return $next($request);
    }
}
