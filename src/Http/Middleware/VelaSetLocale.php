<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use VelaBuild\Core\Services\AdminLanguage;

/**
 * Puts the admin panel (and the login pages) in the admin language; see
 * AdminLanguage for how it is chosen. ?change_language=<code> switches it, and
 * a signed-in account keeps that choice for its next sign-in too.
 */
class VelaSetLocale
{
    public function __construct(private AdminLanguage $languages)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = auth('vela')->user();

        if ($request->has('change_language')) {
            $code = $request->get('change_language');

            if ($this->languages->isAvailable($code)) {
                $request->session()->put(AdminLanguage::SESSION_KEY, $code);

                if ($user instanceof Model && $user->admin_locale !== $code) {
                    $user->forceFill(['admin_locale' => $code])->save();
                }
            }

            return redirect()->to($request->url());
        }

        app()->setLocale($this->languages->forRequest($request, $user));

        return $next($request);
    }
}
