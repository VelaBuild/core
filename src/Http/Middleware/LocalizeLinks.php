<?php

namespace VelaBuild\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Services\LocalizedLinks;

/** Public pages: links stay in the visitor's language (see LocalizedLinks). */
class LocalizeLinks
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$request->isMethod('GET')
            || !str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            || $response->getStatusCode() !== 200
            || preg_match('#^(vela|admin)(/|$)#', $request->path())
            || !method_exists($response, 'setContent')) {
            return $response;
        }
        $locales = array_keys(LaravelLocalization::getSupportedLocales());
        if (count($locales) < 2) {
            return $response;
        }
        $segments = explode('/', trim($request->path(), '/'));
        if (in_array($segments[0] ?? '', $locales, true)) {
            array_shift($segments);
        }
        $content = $response->getContent();
        if (is_string($content) && $content !== '') {
            $response->setContent(LocalizedLinks::rewrite($content, app()->getLocale(), implode('/', $segments)));
        }

        return $response;
    }
}
