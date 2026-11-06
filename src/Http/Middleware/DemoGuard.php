<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use VelaBuild\Core\Models\VelaUser;

/**
 * On a public demo, stop the few actions the reset can't undo in time:
 * anything that would lock the next visitor out of the shared account
 * (password, email, two-factor, removing or editing that user) and the
 * password-reset emails. Everything else is the real admin.
 */
class DemoGuard
{
    /** Blocked for everyone on a demo site. */
    private const BLOCKED = [
        'vela.auth.profile.password.update',
        'vela.auth.profile.password.updateProfile',
        'vela.auth.profile.password.destroyProfile',
        'vela.auth.profile.password.toggleTwoFactor',
        'vela.auth.password.email',
        'vela.auth.password.update',
    ];

    /** Blocked when they would change the shared demo account. */
    private const USER_ROUTES = [
        'vela.admin.users.edit',
        'vela.admin.users.update',
        'vela.admin.users.destroy',
        'vela.admin.users.massDestroy',
    ];

    public function handle(Request $request, Closure $next)
    {
        if (!vela_demo_mode()) {
            return $next($request);
        }
        $name = (string) $request->route()?->getName();

        if (in_array($name, self::BLOCKED, true)
            || (in_array($name, self::USER_ROUTES, true) && $this->touchesDemoUser($request))) {
            $message = __('This is turned off on the demo, so the next visitor can still sign in.');
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 403);
            }

            return back()->withErrors(['demo' => $message]);
        }

        return $next($request);
    }

    private function touchesDemoUser(Request $request): bool
    {
        $demo = VelaUser::where('email', config('vela.demo.email'))->value('id');
        if ($demo === null) {
            return false;
        }
        $target = $request->route('user');
        $ids = array_merge(
            [$target instanceof VelaUser ? $target->id : $target],
            (array) $request->input('ids', [])
        );

        return in_array((string) $demo, array_map('strval', array_filter($ids)), true);
    }
}
