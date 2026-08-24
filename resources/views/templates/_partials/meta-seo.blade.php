    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', config('app.name', 'Vela CMS'))</title>
    {{-- A page with no description of its own falls back to the site's, rather
         than shipping an empty tag that search engines and audits both flag. --}}
    <meta name="description" content="@yield('description', config('vela.site.description') ?: config('vela.site.tagline', ''))">

    <!-- Additional Meta Tags -->
    <meta name="keywords" content="@yield('keywords', '')">
    <meta name="author" content="{{ config('app.name', 'Vela CMS') }}">
    <meta name="robots" content="{{ config('vela.visibility.mode') === 'restricted' && config('vela.visibility.noindex') ? 'noindex, nofollow' : 'index, follow' }}">
    <meta name="language" content="{{ str_replace('_', '-', app()->getLocale()) }}">
    <meta name="revisit-after" content="7 days">

    <!-- Theme color -->
    <meta name="theme-color" content="{{ $themeColor ?? '#1f2937' }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Vela CMS') }}">
