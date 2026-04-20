<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('vela::templates._partials.meta-seo', ['themeColor' => '#0f172a'])
    @include('vela::templates._partials.meta-opengraph')
    @include('vela::templates._partials.meta-pwa')
    @include('vela::templates._partials.hreflang')

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700%7Cplus-jakarta-sans:500,600,700&display=swap" rel="stylesheet">

    <!-- Styles (combined + minified bundles) -->
    @velaAssets('public', 'template-corporate')
    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.14.9/dist/cdn.min.js"></script>

@include('vela::templates._partials.analytics')

    <style>
        /* Critical inline CSS */
        :root {
            --block-accent: #1e40af;
            --block-accent-hover: #1e3a8a;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', sans-serif;
            color: #0f172a;
            line-height: 1.6;
            background: #fff;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            font-weight: 700;
            line-height: 1.25;
            color: #0f172a;
        }
        h1 { font-size: clamp(1.75rem, 4vw, 2.75rem); }
        h2 { font-size: clamp(1.4rem, 3vw, 2rem); }
        h3 { font-size: clamp(1.15rem, 2vw, 1.5rem); }
        a { color: #1e40af; text-decoration: none; }
        a:hover { text-decoration: underline; }
        img { max-width: 100%; height: auto; display: block; }

        .co-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
        }
        .page-row-public.row-contained { max-width: 1200px; padding-left: 24px; padding-right: 24px; }

        /* Navigation */
        .co-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            height: 64px;
            display: flex;
            align-items: center;
        }
        .co-nav-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            height: 100%;
        }
        .co-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 700;
            font-size: 1.15rem;
            color: #0f172a;
            text-decoration: none;
            flex-shrink: 0;
        }
        .co-logo img { width: 36px; height: 36px; object-fit: contain; border-radius: 4px; }
        .co-logo:hover { text-decoration: none; color: #1e40af; }
        .co-nav-links {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .co-nav-links a {
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            color: #374151;
            text-decoration: none;
            white-space: nowrap;
        }
        .co-nav-links a:hover { background: #f1f5f9; color: #1e40af; text-decoration: none; }
        .co-nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Hero */
        .co-hero {
            margin-top: 64px;
            min-height: 520px;
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #1e40af 100%);
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        .co-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
        }
        .co-hero-content {
            position: relative;
            z-index: 1;
        }
        .co-hero h1 { color: #fff; margin-bottom: 16px; }
        .co-hero p { color: #bfdbfe; font-size: 1.15rem; max-width: 560px; margin-bottom: 28px; }

        /* Mobile nav toggle */
        .co-mobile-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            color: #374151;
        }
        .co-main {
            min-height: 60vh;
        }

        @media (max-width: 768px) {
            .co-nav-links { display: none; }
            .co-nav-links.is-open {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                position: fixed;
                top: 64px;
                left: 0;
                right: 0;
                background: #fff;
                padding: 16px 24px;
                border-bottom: 1px solid #e2e8f0;
                gap: 4px;
                z-index: 999;
            }
            .co-nav-links.is-open a { width: 100%; }
            .co-mobile-toggle { display: flex; }
            .co-hero { min-height: 380px; }
        }
    </style>

@include('vela::partials.cookie-consent-styles')
@stack('head')
@include('vela::templates._partials.theme-colors')
@include('vela::templates._partials.custom-css')
</head>
<body class="co-body">

    <!-- Navigation -->
    <nav class="co-nav">
        <div class="co-container">
            <div class="co-nav-inner">
                <a href="{{ route('vela.public.home') }}" class="co-logo">
                    {!! vela_image(asset(config('vela.theme.logo_image', 'images/logo.png')), config('app.name', 'Vela CMS'), [72, 144], 'fit', []) !!}
                    <span>{{ config('app.name', 'Vela CMS') }}</span>
                </a>

                <div class="co-nav-links">
                    <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
@foreach($navPages as $navPage)
                        <a href="{{ LaravelLocalization::getLocalizedURL(app()->getLocale(), '/' . $navPage->slug) }}">{{ $navPage->title }}</a>
@endforeach
                    <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
                    <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
                </div>

                <div class="co-nav-actions">
                    <!-- Language Switcher -->
@php $currentFlag = $flagMap[$currentLocale] ?? 'gb'; @endphp
                    <details class="language-switcher js-click-away">
                        <summary class="co-lang-btn">
                            <img src="{{ asset('flags/1x1/' . $currentFlag . '.svg') }}" alt="{{ $currentLocale }}" width="18" height="18">
                            <span>{{ strtoupper($currentLocale) }}</span>
                            <svg class="language-chevron" width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        </summary>
                        <div class="co-lang-dropdown">
@foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
@php $flagCode = $flagMap[$localeCode] ?? 'gb'; @endphp
                                <a href="{{ LaravelLocalization::getLocalizedURL($localeCode) }}" class="co-lang-option {{ app()->getLocale() == $localeCode ? 'active' : '' }}">
                                    <img src="{{ asset('flags/1x1/' . $flagCode . '.svg') }}" alt="{{ $localeCode }}" width="18" height="18">
                                    <span>{{ $properties['native'] }}</span>
                                </a>
@endforeach
                        </div>
                    </details>

                    <!-- Mobile Toggle -->
                    <button class="co-mobile-toggle" data-toggle-target=".co-nav-links" aria-expanded="false" type="button" aria-label="Toggle navigation">
                        <svg class="icon-open" width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <svg class="icon-close" width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="co-main">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="co-footer">
        <div class="co-container">
            <div class="co-footer-grid">
                <div class="co-footer-brand">
                    <a href="{{ route('vela.public.home') }}" class="co-footer-logo">
                        {!! vela_image(asset(config('vela.theme.logo_image', 'images/logo.png')), config('app.name', 'Vela CMS'), [80, 160], 'fit', []) !!}
                        <span>{{ config('app.name', 'Vela CMS') }}</span>
                    </a>
                    <p>{{ config('vela.site.tagline', '') }}</p>
                </div>

                <div class="co-footer-col">
                    <h3>{{ __('vela::public.quick_links') }}</h3>
                    <ul>
                        <li><a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a></li>
@foreach($navPages as $navPage)
                            <li><a href="{{ LaravelLocalization::getLocalizedURL(app()->getLocale(), '/' . $navPage->slug) }}">{{ $navPage->title }}</a></li>
@endforeach
                    </ul>
                </div>

                <div class="co-footer-col">
                    <h3>{{ __('vela::public.resources') }}</h3>
                    <ul>
                        <li><a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.all_articles') }}</a></li>
                        <li><a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a></li>
                    </ul>
                </div>

                <div class="co-footer-col">
                    <h3>{{ __('vela::public.contact_us') }}</h3>
                    <ul>
                        <li>{{ config('app.name', 'Vela CMS') }}</li>
                    </ul>
                </div>
            </div>

            <div class="co-footer-bottom">
                <span>{{ config('vela.theme.footer_copyright') ?: '© ' . date('Y') . ' ' . config('app.name', 'Vela CMS') . '. ' . __('vela::public.all_rights_reserved') }}</span>
            </div>
        </div>
    </footer>

    @include('vela::templates._partials.scripts-footer')
</body>
</html>
