<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VelaSetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $supportedLocales = array_keys(config('vela.available_languages', []));

        if ($request->has('change_language')) {
            $lang = $request->get('change_language');
            if (in_array($lang, $supportedLocales)) {
                session()->put('admin_locale', $lang);
            }
            return redirect()->to($request->url());
        }

        $locale = session('admin_locale', config('vela.primary_language', 'en'));
        if (in_array($locale, $supportedLocales)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
