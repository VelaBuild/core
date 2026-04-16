<!-- Open Graph Meta Tags -->
<meta property="og:type" content="@yield('og_type', 'website')">
<meta property="og:title" content="@yield('og_title', config('app.name', 'Vela CMS'))">
<meta property="og:description" content="@yield('og_description', '')">
<meta property="og:url" content="@yield('og_url', request()->url())">
<meta property="og:site_name" content="{{ config('app.name', 'Vela CMS') }}">
<meta property="og:locale" content="{{ str_replace('_', '-', app()->getLocale()) }}">
<meta property="og:image" content="@yield('og_image', asset('images/hero-image.jpg'))">
<meta property="og:image:width" content="@yield('og_image_width', '1200')">
<meta property="og:image:height" content="@yield('og_image_height', '630')">
<meta property="og:image:alt" content="@yield('og_image_alt', config('app.name', 'Vela CMS'))">

<!-- Twitter Card Meta Tags -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="@yield('twitter_title', config('app.name', 'Vela CMS'))">
<meta name="twitter:description" content="@yield('twitter_description', '')">
<meta name="twitter:image" content="@yield('twitter_image', asset('images/hero-image.jpg'))">
<meta name="twitter:image:alt" content="@yield('twitter_image_alt', config('app.name', 'Vela CMS'))">
