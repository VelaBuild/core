<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('vela::templates._partials.meta-seo', ['themeColor' => '#0a0a0a'])
    @include('vela::templates._partials.meta-opengraph')
    @include('vela::templates._partials.meta-pwa')
    @include('vela::templates._partials.hreflang')

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700&display=swap" rel="stylesheet" />

    <!-- Styles -->
    <link href="{{ asset('vendor/vela/css/page-blocks.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/vela/css/dark/style.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/vela/css/dark/style-deferred.css') }}" rel="stylesheet" media="print" onload="this.media='all'">

    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.14.9/dist/cdn.min.js"></script>

    @include('vela::templates._partials.analytics')

    <style>
        /* Critical inline CSS — Dark Theme */
        :root {
            --block-accent: #14b8a6;
            --block-accent-hover: #0d9488;
            --block-text-primary: #e5e5e5;
            --block-text-secondary: #d4d4d4;
            --block-text-muted: #a3a3a3;
            --block-border: #262626;
            --block-bg-light: #141414;
            --block-bg-hover: #1a1a1a;
            --block-bg-white: #0a0a0a;
            --block-form-border: #404040;
            --block-overlay: rgba(0,0,0,0.8);
            --block-overlay-light: rgba(0,0,0,0.7);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            color: #e5e5e5;
            line-height: 1.7;
            background: #0a0a0a;
            font-size: 1rem;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Inter', system-ui, sans-serif;
            font-weight: 700;
            line-height: 1.2;
            color: #f5f5f5;
        }
        h1 { font-size: clamp(2rem, 5vw, 3.2rem); }
        h2 { font-size: clamp(1.5rem, 3vw, 2.2rem); }
        h3 { font-size: clamp(1.15rem, 2vw, 1.5rem); }
        a { color: #14b8a6; text-decoration: none; }
        a:hover { text-decoration: none; }
        img { max-width: 100%; height: auto; display: block; }

        .dk-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* Navigation */
        .dk-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: rgba(10,10,10,0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid #1f1f1f;
            height: 64px;
        }
        .dk-nav-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 64px;
        }
        .dk-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            flex-shrink: 0;
        }
        .dk-logo img { width: 36px; height: 36px; object-fit: contain; border-radius: 8px; }
        .dk-logo-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: #f5f5f5;
            letter-spacing: -0.02em;
        }
        .dk-logo:hover { text-decoration: none; }

        .dk-nav-links {
            display: flex;
            align-items: center;
            gap: 2px;
        }
        .dk-nav-links a {
            display: inline-flex;
            align-items: center;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            color: #a3a3a3;
            text-decoration: none;
            white-space: nowrap;
        }
        .dk-nav-links a:hover {
            background: #1a1a1a;
            color: #e5e5e5;
            text-decoration: none;
        }
        .dk-nav-links a.active {
            background: #14b8a6;
            color: #0a0a0a;
        }

        .dk-nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        /* Mobile nav toggle */
        .dk-mobile-toggle {
            display: none;
            background: none;
            border: 1.5px solid #262626;
            border-radius: 8px;
            cursor: pointer;
            padding: 7px 10px;
            color: #a3a3a3;
        }

        .dk-hero {
            min-height: 520px;
            padding-top: 64px;
            display: flex;
            align-items: center;
        }

        .dk-main {
            padding-top: 64px;
            min-height: 60vh;
        }

        @media (max-width: 768px) {
            .dk-nav-links { display: none; }
            .dk-nav-links.is-open {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: #0a0a0a;
                padding: 80px 24px 24px;
                gap: 4px;
                z-index: 998;
                overflow-y: auto;
            }
            .dk-nav-links.is-open a {
                border-radius: 10px;
                padding: 12px 18px;
                font-size: 1rem;
            }
            .dk-mobile-toggle { display: flex; align-items: center; justify-content: center; }
        }
    </style>

    @stack('head')
    @include('vela::templates._partials.theme-colors')
    @include('vela::templates._partials.custom-css')
</head>
<body class="dk-body">

    <!-- Navigation -->
    <nav class="dk-nav" x-data="{ mobileOpen: false }">
        <div class="dk-container">
            <div class="dk-nav-inner">
                <a href="{{ route('vela.public.home') }}" class="dk-logo">
                    {!! vela_image(asset(config('vela.theme.logo_image', 'images/logo.png')), config('app.name', 'Vela CMS'), [72, 144], 'fit', []) !!}
                    <span class="dk-logo-name">{{ config('app.name', 'Vela CMS') }}</span>
                </a>

                <div class="dk-nav-links" :class="{ 'is-open': mobileOpen }">
                    <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
                    @foreach($navPages as $navPage)
                        <a href="{{ LaravelLocalization::getLocalizedURL(app()->getLocale(), '/' . $navPage->slug) }}">{{ $navPage->title }}</a>
                    @endforeach
                    <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
                    <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
                </div>

                <div class="dk-nav-actions">
                    <!-- Language Switcher -->
                    <div class="relative" x-data="{ open: false }">
                        @php $currentFlag = $flagMap[$currentLocale] ?? 'gb'; @endphp
                        <button @click="open = !open" class="dk-lang-btn" type="button">
                            <img src="{{ asset('flags/1x1/' . $currentFlag . '.svg') }}" alt="{{ $currentLocale }}" width="16" height="16">
                            <span>{{ strtoupper($currentLocale) }}</span>
                        </button>
                        <div x-show="open" @click.away="open = false" x-transition class="dk-lang-dropdown">
                            @foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
                                @php $flagCode = $flagMap[$localeCode] ?? 'gb'; @endphp
                                <a href="{{ LaravelLocalization::getLocalizedURL($localeCode) }}"
                                   class="dk-lang-option {{ app()->getLocale() == $localeCode ? 'active' : '' }}">
                                    <img src="{{ asset('flags/1x1/' . $flagCode . '.svg') }}" alt="{{ $localeCode }}" width="16" height="16">
                                    <span>{{ $properties['native'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>

                    <!-- Mobile Toggle -->
                    <button class="dk-mobile-toggle" @click="mobileOpen = !mobileOpen" type="button" aria-label="Toggle navigation">
                        <svg x-show="!mobileOpen" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <svg x-show="mobileOpen" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="dk-main">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="dk-footer">
        <div class="dk-footer-top-border"></div>
        <div class="dk-container">
            <div class="dk-footer-inner">
                <div class="dk-footer-brand">
                    <a href="{{ route('vela.public.home') }}" class="dk-footer-logo">
                        <span class="dk-footer-logo-text">{{ config('app.name', 'Vela CMS') }}</span>
                    </a>
                    <p>{{ config('vela.site.tagline', '') }}</p>
                </div>

                <div class="dk-footer-links">
                    <div class="dk-footer-col">
                        <h4>{{ __('vela::public.navigate') }}</h4>
                        <nav>
                            <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
                            <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
                            <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
                            @foreach($navPages as $navPage)
                                <a href="{{ LaravelLocalization::getLocalizedURL(app()->getLocale(), '/' . $navPage->slug) }}">{{ $navPage->title }}</a>
                            @endforeach
                        </nav>
                    </div>
                </div>
            </div>

            <div class="dk-footer-bottom">
                <span>{{ config('vela.theme.footer_copyright') ?: '© ' . date('Y') . ' ' . config('app.name', 'Vela CMS') . '. ' . __('vela::public.all_rights_reserved') }}</span>
            </div>
        </div>
    </footer>

    @include('vela::templates._partials.scripts-footer')
</body>
</html>
