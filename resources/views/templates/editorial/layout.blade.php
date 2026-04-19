<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('vela::templates._partials.meta-seo', ['themeColor' => '#111827'])
    @include('vela::templates._partials.meta-opengraph')
    @include('vela::templates._partials.meta-pwa')
    @include('vela::templates._partials.hreflang')

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=lora:400,500,600,700%7Cinter:300,400,500&display=swap" rel="stylesheet">

    <!-- Styles -->
    <link href="{{ asset('vendor/vela/css/page-blocks.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/vela/css/editorial/style.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/vela/css/editorial/style-deferred.css') }}" rel="stylesheet" media="print" onload="this.media='all'">

    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.14.9/dist/cdn.min.js"></script>

@include('vela::templates._partials.analytics')

    <style>
        /* Critical inline CSS */
        :root {
            --block-accent: #b91c1c;
            --block-accent-hover: #991b1b;
            --block-bg-light: #f8f7f4;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', sans-serif;
            color: #111827;
            line-height: 1.8;
            background: #fff;
            font-size: 1.05rem;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Lora', Georgia, serif;
            font-weight: 700;
            line-height: 1.25;
            color: #111827;
        }
        h1 { font-size: clamp(1.9rem, 4.5vw, 3rem); }
        h2 { font-size: clamp(1.5rem, 3vw, 2.1rem); }
        h3 { font-size: clamp(1.2rem, 2vw, 1.5rem); }
        a { color: #b91c1c; text-decoration: none; }
        a:hover { text-decoration: underline; }
        img { max-width: 100%; height: auto; display: block; }

        .ed-container {
            max-width: 1160px;
            margin: 0 auto;
            padding: 0 24px;
        }
        .ed-container-narrow {
            max-width: 760px;
            margin: 0 auto;
            padding: 0 24px;
        }
        .page-row-public.row-contained { max-width: 1160px; padding-left: 24px; padding-right: 24px; }

        /* Navigation — magazine masthead */
        .ed-topbar {
            background: #111827;
            color: #9ca3af;
            font-size: 0.78rem;
            padding: 6px 0;
            text-align: center;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .ed-topbar a { color: #9ca3af; text-decoration: none; }
        .ed-topbar a:hover { color: #fff; text-decoration: none; }

        .ed-masthead {
            background: #fff;
            border-bottom: 3px solid #111827;
            padding: 20px 0 16px;
        }
        .ed-masthead-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }
        .ed-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            flex-shrink: 0;
        }
        .ed-logo img { width: 40px; height: 40px; object-fit: contain; border-radius: 4px; }
        .ed-logo-name {
            font-family: 'Lora', Georgia, serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: #111827;
            line-height: 1;
        }
        .ed-logo:hover { text-decoration: none; }
        .ed-logo:hover .ed-logo-name { color: #b91c1c; }

        .ed-nav-links {
            display: flex;
            align-items: center;
            gap: 0;
            border-left: 1px solid #e5e7eb;
        }
        .ed-nav-links a {
            display: block;
            padding: 6px 18px;
            font-size: 0.82rem;
            font-weight: 500;
            color: #374151;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-right: 1px solid #e5e7eb;
            white-space: nowrap;
        }
        .ed-nav-links a:hover { color: #b91c1c; text-decoration: none; background: #fef2f2; }

        .ed-nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        /* Mobile nav toggle */
        .ed-mobile-toggle {
            display: none;
            background: none;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            cursor: pointer;
            padding: 6px 10px;
            color: #374151;
        }

        .ed-main {
            min-height: 60vh;
        }

        @media (max-width: 768px) {
            .ed-nav-links { display: none; }
            .ed-nav-links.is-open {
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
                gap: 0;
                z-index: 998;
                overflow-y: auto;
                border-left: none;
            }
            .ed-nav-links.is-open a {
                border-right: none;
                border-bottom: 1px solid #f3f4f6;
                padding: 14px 0;
                font-size: 1rem;
            }
            .ed-mobile-toggle { display: flex; align-items: center; justify-content: center; }
            .ed-masthead-inner { flex-wrap: wrap; }
        }
    </style>

@stack('head')
@include('vela::templates._partials.theme-colors')
@include('vela::templates._partials.custom-css')
</head>
<body class="ed-body">

    <!-- Top bar -->
    <div class="ed-topbar">
        <div class="ed-container">
            <span>{{ config('app.name', 'Vela CMS') }}</span>
            <span style="margin: 0 12px;">·</span>
            <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
            <span style="margin: 0 12px;">·</span>
            <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
        </div>
    </div>

    <!-- Masthead / Navigation -->
    <header class="ed-masthead">
        <div class="ed-container">
            <div class="ed-masthead-inner">
                <a href="{{ route('vela.public.home') }}" class="ed-logo">
                    {!! vela_image(asset(config('vela.theme.logo_image', 'images/logo.png')), config('app.name', 'Vela CMS'), [80, 160], 'fit', []) !!}
                    <span class="ed-logo-name">{{ config('app.name', 'Vela CMS') }}</span>
                </a>

                <nav class="ed-nav-links">
                    <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
@foreach($navPages as $navPage)
                        <a href="{{ LaravelLocalization::getLocalizedURL(app()->getLocale(), '/' . $navPage->slug) }}">{{ $navPage->title }}</a>
@endforeach
                    <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
                    <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
                </nav>

                <div class="ed-nav-actions">
                    <!-- Language Switcher -->
                    <details class="language-switcher js-click-away">
@php $currentFlag = $flagMap[$currentLocale] ?? 'gb'; @endphp
                        <summary class="ed-lang-btn">
                            <img src="{{ asset('flags/1x1/' . $currentFlag . '.svg') }}" alt="{{ $currentLocale }}" width="16" height="16">
                            <span>{{ strtoupper($currentLocale) }}</span>
                        </summary>
                        <div class="ed-lang-dropdown">
@foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
@php $flagCode = $flagMap[$localeCode] ?? 'gb'; @endphp
                                <a href="{{ LaravelLocalization::getLocalizedURL($localeCode) }}" class="ed-lang-option {{ app()->getLocale() == $localeCode ? 'active' : '' }}">
                                    <img src="{{ asset('flags/1x1/' . $flagCode . '.svg') }}" alt="{{ $localeCode }}" width="16" height="16">
                                    <span>{{ $properties['native'] }}</span>
                                </a>
@endforeach
                        </div>
                    </details>

                    <!-- Mobile Toggle -->
                    <button class="ed-mobile-toggle" data-toggle-target=".ed-nav-links" aria-expanded="false" type="button" aria-label="Toggle navigation">
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
    </header>

    <!-- Main Content -->
    <main class="ed-main">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="ed-footer">
        <div class="ed-container">
            <div class="ed-footer-inner">
                <div class="ed-footer-brand">
                    <a href="{{ route('vela.public.home') }}" class="ed-footer-logo">
                        <span>{{ config('app.name', 'Vela CMS') }}</span>
                    </a>
                    <p>{{ config('vela.site.tagline', '') }}</p>
                </div>

                <nav class="ed-footer-nav">
                    <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
@foreach($navPages as $navPage)
                        <a href="{{ LaravelLocalization::getLocalizedURL(app()->getLocale(), '/' . $navPage->slug) }}">{{ $navPage->title }}</a>
@endforeach
                    <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
                    <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
                </nav>
            </div>

            <div class="ed-footer-bottom">
                <span>{{ config('vela.theme.footer_copyright') ?: '© ' . date('Y') . ' ' . config('app.name', 'Vela CMS') . '. ' . __('vela::public.all_rights_reserved') }}</span>
            </div>
        </div>
    </footer>

    @include('vela::templates._partials.scripts-footer')
</body>
</html>
