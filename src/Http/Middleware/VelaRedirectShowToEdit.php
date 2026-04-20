<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Redirect any `vela.admin.*.show` route to the corresponding
 * `vela.admin.*.edit` route.
 *
 * Rationale: the default Laravel resource scaffold ships separate
 * show + edit pages. For Vela admin CRUDs the show page is "ugly and
 * pointless" — the edit page already displays every field. Collapsing
 * them to a single action makes the admin less cluttered and avoids
 * users having to guess which of two buttons they want.
 *
 * Users with read-only (view) permission but no edit permission still
 * get their old show page — the fallback in
 * resources/views/partials/datatablesActions.blade.php handles that.
 *
 * Applied via the `vela.admin` middleware group.
 */
class VelaRedirectShowToEdit
{
    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();
        if (!$route) return $next($request);

        $name = $route->getName();
        if (!$name || !str_starts_with($name, 'vela.admin.') || !str_ends_with($name, '.show')) {
            return $next($request);
        }

        $editName = substr($name, 0, -5) . '.edit';
        if (!Route::has($editName)) {
            return $next($request);
        }

        return redirect()->route($editName, $route->parameters(), 302);
    }
}
