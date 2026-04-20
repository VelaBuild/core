<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('vela::templates._partials.meta-seo', ['themeColor' => '#7c3aed'])
    @include('vela::templates._partials.meta-opengraph')
    @include('vela::templates._partials.meta-pwa')
    @include('vela::templates._partials.hreflang')

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=outfit:300,400,500,600,700&display=swap" rel="stylesheet" />

    <!-- Styles -->
    <link href="{{ asset('vendor/vela/css/page-blocks.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/vela/css/modern/style.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/vela/css/modern/style-deferred.css') }}" rel="stylesheet" media="print" onload="this.media='all'">

    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.14.9/dist/cdn.min.js"></script>

@include('vela::templates._partials.analytics')

    <style>
        /* Critical inline CSS */
        :root {
            --block-accent: #7c3aed;
            --block-accent-hover: #6d28d9;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Outfit', system-ui, sans-serif;
            color: #1e1b4b;
            line-height: 1.7;
            background: #fff;
            font-size: 1rem;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Outfit', system-ui, sans-serif;
            font-weight: 700;
            line-height: 1.2;
            color: #1e1b4b;
        }
        h1 { font-size: clamp(2rem, 5vw, 3.2rem); }
        h2 { font-size: clamp(1.5rem, 3vw, 2.2rem); }
        h3 { font-size: clamp(1.15rem, 2vw, 1.5rem); }
        a { color: #7c3aed; text-decoration: none; }
        a:hover { text-decoration: none; }
        img { max-width: 100%; height: auto; display: block; }

        .md-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
        }
        .page-row-public.row-contained { max-width: 1200px; padding-left: 24px; padding-right: 24px; }

        /* Navigation */
        .md-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(124,58,237,0.1);
            height: 64px;
        }
        .md-nav-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 64px;
        }
        .md-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            flex-shrink: 0;
        }
        .md-logo img { width: 36px; height: 36px; object-fit: contain; border-radius: 8px; }
        .md-logo-name {
            font-size: 1.25rem;
            font-weight: 700;
            background: linear-gradient(135deg, #7c3aed, #2563eb);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .md-logo:hover { text-decoration: none; }

        .md-nav-links {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .md-nav-links a {
            display: inline-flex;
            align-items: center;
            padding: 7px 16px;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 500;
            color: #374151;
            text-decoration: none;
            white-space: nowrap;
        }
        .md-nav-links a:hover {
            background: #f5f3ff;
            color: #7c3aed;
            text-decoration: none;
        }
        .md-nav-links a.active {
            background: linear-gradient(135deg, #7c3aed, #2563eb);
            color: #fff;
        }

        .md-nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        /* Mobile nav toggle */
        .md-mobile-toggle {
            display: none;
            background: none;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            cursor: pointer;
            padding: 7px 10px;
            color: #374151;
        }

        .md-hero {
            min-height: 520px;
            padding-top: 64px;
            display: flex;
            align-items: center;
        }

        .md-main {
            padding-top: 64px;
            min-height: 60vh;
        }

        @media (max-width: 768px) {
            .md-nav-links { display: none; }
            .md-nav-links.is-open {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: #fff;
                padding: 80px 24px 24px;
                gap: 4px;
                z-index: 998;
                overflow-y: auto;
            }
            .md-nav-links.is-open a {
                border-radius: 12px;
                padding: 12px 18px;
                font-size: 1rem;
            }
            .md-mobile-toggle { display: flex; align-items: center; justify-content: center; }
        }
    </style>

@include('vela::partials.cookie-consent-styles')
@stack('head')
@include('vela::templates._partials.theme-colors')
@include('vela::templates._partials.custom-css')
</head>
<body class="md-body">

    <!-- Navigation -->
    <nav class="md-nav">
        <div class="md-container">
            <div class="md-nav-inner">
                <a href="{{ route('vela.public.home') }}" class="md-logo">
                    {!! vela_image(asset(config('vela.theme.logo_image', 'images/logo.png')), config('app.name', 'Vela CMS'), [72, 144], 'fit', []) !!}
                    <span class="md-logo-name">{{ config('app.name', 'Vela CMS') }}</span>
                </a>

                <div class="md-nav-links">
                    <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
@foreach($navPages as $navPage)
                        <a href="{{ LaravelLocalization::getLocalizedURL(app()->getLocale(), '/' . $navPage->slug) }}">{{ $navPage->title }}</a>
@endforeach
                    <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
                    <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
                </div>

                <div class="md-nav-actions">
                    <!-- Language Switcher -->
                    <details class="language-switcher js-click-away">
@php $currentFlag = $flagMap[$currentLocale] ?? 'gb'; @endphp
                        <summary class="md-lang-btn">
                            <img src="{{ asset('flags/1x1/' . $currentFlag . '.svg') }}" alt="{{ $currentLocale }}" width="16" height="16">
                            <span>{{ strtoupper($currentLocale) }}</span>
                        </summary>
                        <div class="md-lang-dropdown">
@foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
@php $flagCode = $flagMap[$localeCode] ?? 'gb'; @endphp
                                <a href="{{ LaravelLocalization::getLocalizedURL($localeCode) }}" class="md-lang-option {{ app()->getLocale() == $localeCode ? 'active' : '' }}">
                                    <img src="{{ asset('flags/1x1/' . $flagCode . '.svg') }}" alt="{{ $localeCode }}" width="16" height="16">
                                    <span>{{ $properties['native'] }}</span>
                                </a>
@endforeach
                        </div>
                    </details>

                    <!-- Mobile Toggle -->
                    <button class="md-mobile-toggle" data-toggle-target=".md-nav-links" aria-expanded="false" type="button" aria-label="Toggle navigation">
                        <svg class="icon-open" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <svg class="icon-close" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="md-main">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="md-footer">
        <div class="md-footer-gradient-bar"></div>
        <div class="md-container">
            <div class="md-footer-inner">
                <div class="md-footer-brand">
                    <a href="{{ route('vela.public.home') }}" class="md-footer-logo">
                        <span class="md-footer-logo-text">{{ config('app.name', 'Vela CMS') }}</span>
                    </a>
                    <p>{{ config('vela.site.tagline', '') }}</p>
                </div>

                <div class="md-footer-links">
                    <div class="md-footer-col">
                        <h3>{{ __('vela::public.navigate') }}</h3>
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

            <div class="md-footer-bottom">
                <span>{{ config('vela.theme.footer_copyright') ?: '© ' . date('Y') . ' ' . config('app.name', 'Vela CMS') . '. ' . __('vela::public.all_rights_reserved') }}</span>
            </div>
        </div>
    </footer>

    @include('vela::templates._partials.scripts-footer')
</body>
</html>
