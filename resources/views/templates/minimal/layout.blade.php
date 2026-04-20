<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('vela::templates._partials.meta-seo', ['themeColor' => '#ffffff'])
    @include('vela::templates._partials.meta-opengraph')
    @include('vela::templates._partials.meta-pwa')
    @include('vela::templates._partials.hreflang')

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=source-serif-4:400,500,600,700%7Cinter:300,400,500,600&display=swap" rel="stylesheet">

    <!-- Styles (combined + minified bundle) -->
    @velaAssets('public')

    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.14.9/dist/cdn.min.js"></script>

@include('vela::templates._partials.analytics')

    <style>
        /* Minimal Template Styles */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #1a1a1a;
            background: var(--vela-background, #fff);
            line-height: 1.7;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Source Serif 4', Georgia, serif;
            font-weight: 600;
            line-height: 1.3;
        }

        a { color: inherit; text-decoration: none; }
        img { max-width: 100%; height: auto; display: block; }

        .mn-container { max-width: 1100px; margin: 0 auto; padding: 0 24px; }
        .mn-narrow { max-width: 720px; margin: 0 auto; padding: 0 24px; }
        .page-row-public.row-contained { max-width: 1100px; padding-left: 24px; padding-right: 24px; }

        /* Navigation */
        .mn-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #fff;
            border-bottom: 1px solid #e5e5e5;
        }
        .mn-nav-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 64px;
        }
        .mn-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: 'Source Serif 4', Georgia, serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: #1a1a1a;
        }
        .mn-logo img { height: 36px; width: auto; }
        .mn-nav-links { display: flex; align-items: center; gap: 28px; }
        .mn-nav-links a {
            font-size: 0.9rem;
            font-weight: 500;
            color: #555;
            transition: color 0.2s;
        }
        .mn-nav-links a:hover { color: #1a1a1a; }

        /* Language Switcher */
        .mn-lang-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            color: #555;
            background: none;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 4px 10px;
            cursor: pointer;
            transition: border-color 0.2s;
        }
        .mn-lang-btn:hover { border-color: #999; }
        .mn-lang-btn img { width: 18px; height: 18px; border-radius: 50%; object-fit: cover; }
        .mn-lang-dropdown {
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 4px;
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            min-width: 180px;
            z-index: 200;
            overflow: hidden;
        }
        .mn-lang-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            font-size: 0.85rem;
            color: #333;
            transition: background 0.15s;
        }
        .mn-lang-option:hover { background: #f5f5f5; }
        .mn-lang-option.active { background: #f0f0f0; font-weight: 600; }
        .mn-lang-option img { width: 18px; height: 18px; border-radius: 50%; object-fit: cover; }

        /* Mobile menu */
        .mn-mobile-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
        }
        .mn-mobile-actions { display: none; align-items: center; gap: 12px; }
        .mn-lang-mobile { display: none; }
        @media (max-width: 768px) {
            .mn-nav-links { display: none; }
            .mn-nav-links.is-open {
                display: flex;
                flex-direction: column;
                position: absolute;
                top: 64px;
                left: 0;
                right: 0;
                background: #fff;
                padding: 16px 24px;
                border-bottom: 1px solid #e5e5e5;
                gap: 16px;
                z-index: 200;
            }
            .mn-mobile-toggle { display: flex; }
            .mn-mobile-actions { display: flex; }
            .mn-lang-mobile { display: block; }
            .mn-lang-desktop { display: none; }
        }

        /* Hero */
        .mn-hero {
            padding: 80px 0;
            text-align: center;
            border-bottom: 1px solid #e5e5e5;
        }
        .mn-hero h1 {
            font-size: 3rem;
            margin-bottom: 16px;
            letter-spacing: -0.02em;
        }
        .mn-hero p {
            font-size: 1.15rem;
            color: #666;
            max-width: 560px;
            margin: 0 auto 32px;
        }
        .mn-hero-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        @media (max-width: 640px) {
            .mn-hero { padding: 48px 0; }
            .mn-hero h1 { font-size: 2rem; }
        }

        /* Buttons */
        .mn-btn {
            display: inline-block;
            padding: 10px 24px;
            font-size: 0.9rem;
            font-weight: 500;
            border-radius: 6px;
            transition: all 0.2s;
            cursor: pointer;
        }
        .mn-btn-primary {
            background: var(--vela-primary, #1a1a1a);
            color: #fff;
            border: 1px solid var(--vela-primary, #1a1a1a);
        }
        .mn-btn-primary:hover { background: var(--vela-primary, #333); filter: brightness(1.2); }
        .mn-btn-outline {
            background: transparent;
            color: #1a1a1a;
            border: 1px solid #ccc;
        }
        .mn-btn-outline:hover { border-color: #1a1a1a; }

        /* Section */
        .mn-section { padding: 64px 0; }
        .mn-section-alt { background: #fafafa; }
        .mn-section-header {
            margin-bottom: 40px;
        }
        .mn-section-header h2 {
            font-size: 2rem;
            margin-bottom: 8px;
        }
        .mn-section-header p {
            font-size: 1rem;
            color: #666;
        }

        /* Article Grid */
        .mn-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 32px;
        }
        @media (max-width: 640px) {
            .mn-grid { grid-template-columns: 1fr; gap: 24px; }
        }

        /* Article Card */
        .mn-card {
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            overflow: hidden;
            transition: box-shadow 0.2s, transform 0.2s;
            background: #fff;
        }
        .mn-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            transform: translateY(-2px);
        }
        .mn-card-img {
            aspect-ratio: 16/10;
            overflow: hidden;
            background: #f0f0f0;
        }
        .mn-card-img img { width: 100%; height: 100%; object-fit: cover; }
        .mn-card-body { padding: 20px; }
        .mn-card-meta {
            font-size: 0.8rem;
            color: #888;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .mn-card-meta a { color: #1a1a1a; font-weight: 500; }
        .mn-card-meta a:hover { text-decoration: underline; }
        .mn-card-title {
            font-size: 1.2rem;
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .mn-card-title a:hover { text-decoration: underline; }
        .mn-card-desc {
            font-size: 0.9rem;
            color: #666;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Category chips */
        .mn-chips { display: flex; flex-wrap: wrap; gap: 10px; }
        .mn-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s;
            background: #fff;
        }
        .mn-chip:hover { border-color: #1a1a1a; background: #fafafa; }
        .mn-chip img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
        .mn-chip-count { font-size: 0.8rem; color: #888; font-weight: 400; }

        /* Article page */
        .mn-article-header { padding: 48px 0 0; }
        .mn-article-header .mn-badge {
            display: inline-block;
            background: #f0f0f0;
            color: #333;
            font-size: 0.8rem;
            font-weight: 500;
            padding: 4px 12px;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        .mn-article-header h1 {
            font-size: 2.5rem;
            margin-bottom: 12px;
            letter-spacing: -0.02em;
        }
        .mn-article-header .mn-subtitle {
            font-size: 1.15rem;
            color: #666;
            margin-bottom: 16px;
        }
        .mn-article-header .mn-date { font-size: 0.85rem; color: #888; }
        .mn-article-img {
            margin: 32px 0;
            border-radius: 8px;
            overflow: hidden;
        }
        .mn-article-img img { width: 100%; }
        .mn-prose { font-size: 1.05rem; line-height: 1.8; color: #333; }
        .mn-prose p { margin-bottom: 1.2em; }
        .mn-prose h2 { font-size: 1.6rem; margin: 2em 0 0.6em; }
        .mn-prose h3 { font-size: 1.3rem; margin: 1.5em 0 0.5em; }
        .mn-prose ul, .mn-prose ol { margin-bottom: 1.2em; padding-left: 1.5em; }
        .mn-prose li { margin-bottom: 0.4em; }
        .mn-prose img { border-radius: 8px; margin: 1.5em 0; }
        .mn-prose strong { font-weight: 600; }
        .mn-prose code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.9em;
        }
        .mn-prose blockquote {
            border-left: 3px solid #ddd;
            padding-left: 20px;
            margin: 1.5em 0;
            color: #666;
            font-style: italic;
        }
        @media (max-width: 640px) {
            .mn-article-header h1 { font-size: 1.75rem; }
        }

        /* Share */
        .mn-share {
            display: flex;
            gap: 10px;
            padding: 24px 0;
            margin-top: 40px;
            border-top: 1px solid #e5e5e5;
        }
        .mn-share a {
            padding: 8px 16px;
            font-size: 0.85rem;
            font-weight: 500;
            border-radius: 6px;
            transition: opacity 0.2s;
        }
        .mn-share a:hover { opacity: 0.85; }
        .mn-share-fb { background: #1877f2; color: #fff; }
        .mn-share-tw { background: #1da1f2; color: #fff; }

        /* Breadcrumb */
        .mn-breadcrumb {
            padding: 16px 0;
            font-size: 0.85rem;
            color: #888;
            border-bottom: 1px solid #f0f0f0;
        }
        .mn-breadcrumb a { color: #888; transition: color 0.2s; }
        .mn-breadcrumb a:hover { color: #1a1a1a; }
        .mn-breadcrumb span { margin: 0 8px; }

        /* Page header */
        .mn-page-header {
            padding: 48px 0;
            text-align: center;
            border-bottom: 1px solid #e5e5e5;
        }
        .mn-page-header h1 {
            font-size: 2.5rem;
            margin-bottom: 8px;
        }
        .mn-page-header p {
            font-size: 1.05rem;
            color: #666;
            max-width: 560px;
            margin: 0 auto;
        }

        /* Pagination */
        .mn-pagination { margin-top: 40px; text-align: center; }

        /* Footer */
        .mn-footer {
            border-top: 1px solid #e5e5e5;
            padding: 48px 0 32px;
            color: #888;
            font-size: 0.85rem;
        }
        .mn-footer-inner {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 32px;
        }
        .mn-footer-brand { max-width: 320px; }
        .mn-footer-brand .mn-logo { margin-bottom: 12px; }
        .mn-footer-brand p { color: #888; font-size: 0.85rem; line-height: 1.6; }
        .mn-footer-links h4 {
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 12px;
        }
        .mn-footer-links ul { list-style: none; }
        .mn-footer-links li { margin-bottom: 8px; }
        .mn-footer-links a { color: #888; transition: color 0.2s; }
        .mn-footer-links a:hover { color: #1a1a1a; }
        .mn-footer-bottom {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e5e5;
            text-align: center;
            color: #aaa;
        }
        @media (max-width: 640px) {
            .mn-footer-inner { flex-direction: column; }
        }

        /* Placeholder for missing images */
        .mn-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f0f0;
            color: #bbb;
        }
        .mn-placeholder svg { width: 40px; height: 40px; }
    </style>
@include('vela::partials.cookie-consent-styles')
@stack('head')
@include('vela::templates._partials.theme-colors')
@include('vela::templates._partials.custom-css')
</head>
<body>
    <!-- Navigation -->
    <nav class="mn-nav">
        <div class="mn-container">
            <div class="mn-nav-inner">
                <a href="{{ route('vela.public.home') }}" class="mn-logo">
                    {!! vela_image(asset(config('vela.theme.logo_image', 'images/logo.png')), config('app.name', 'Vela CMS'), [100, 200], 'fit', []) !!}
                    <span>{{ config('app.name', 'Vela CMS') }}</span>
                </a>

                <div class="mn-nav-links">
                    <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
@foreach($navPages as $navPage)
                        <a href="{{ LaravelLocalization::getLocalizedURL(app()->getLocale(), '/' . $navPage->slug) }}">{{ $navPage->title }}</a>
@endforeach
                    <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
                    <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>

                    <!-- Language Switcher -->
@php
                        $currentLocale = app()->getLocale();
                        $flagMap = [
                            'en' => 'gb', 'th' => 'th', 'zh-Hans' => 'cn', 'de' => 'de',
                            'nl' => 'nl', 'fr' => 'fr', 'it' => 'it', 'dk' => 'dk',
                            'ru' => 'ru', 'ar' => 'sa',
                        ];
                        $currentFlag = $flagMap[$currentLocale] ?? 'gb';
@endphp
                    <details class="language-switcher js-click-away mn-lang-desktop">
                        <summary class="mn-lang-btn">
                            <img src="{{ asset('flags/1x1/' . $currentFlag . '.svg') }}" alt="{{ $currentLocale }}">
                            {{ strtoupper($currentLocale) }}
                        </summary>
                        <div class="mn-lang-dropdown">
@foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
@php $flagCode = $flagMap[$localeCode] ?? 'gb'; @endphp
                                <a href="{{ LaravelLocalization::getLocalizedURL($localeCode) }}" class="mn-lang-option {{ app()->getLocale() == $localeCode ? 'active' : '' }}">
                                    <img src="{{ asset('flags/1x1/' . $flagCode . '.svg') }}" alt="{{ $localeCode }}">
                                    {{ $properties['native'] }}
                                </a>
@endforeach
                        </div>
                    </details>
                </div>

                <!-- Mobile -->
                <div class="mn-mobile-actions">
                    <details class="language-switcher js-click-away mn-lang-mobile">
                        <summary class="mn-lang-btn">
                            <img src="{{ asset('flags/1x1/' . $currentFlag . '.svg') }}" alt="{{ $currentLocale }}">
                            {{ strtoupper($currentLocale) }}
                        </summary>
                        <div class="mn-lang-dropdown">
@foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
@php $flagCode = $flagMap[$localeCode] ?? 'gb'; @endphp
                                <a href="{{ LaravelLocalization::getLocalizedURL($localeCode) }}" class="mn-lang-option {{ app()->getLocale() == $localeCode ? 'active' : '' }}">
                                    <img src="{{ asset('flags/1x1/' . $flagCode . '.svg') }}" alt="{{ $localeCode }}">
                                    {{ $properties['native'] }}
                                </a>
@endforeach
                        </div>
                    </details>
                    <button class="mn-mobile-toggle" data-toggle-target=".mn-nav-links" aria-expanded="false" type="button" aria-label="Toggle navigation">
                        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main>
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="mn-footer">
        <div class="mn-container">
            <div class="mn-footer-inner">
                <div class="mn-footer-brand">
                    <a href="{{ route('vela.public.home') }}" class="mn-logo">
                        {!! vela_image(asset(config('vela.theme.logo_image', 'images/logo.png')), config('app.name', 'Vela CMS'), [100, 200], 'fit', []) !!}
                        <span>{{ config('app.name', 'Vela CMS') }}</span>
                    </a>
                    <p>{{ config('vela.site.tagline', '') }}</p>
                </div>

                <div class="mn-footer-links">
                    <h3>{{ __('vela::public.quick_links') }}</h3>
                    <ul>
                        <li><a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a></li>
@foreach($navPages as $navPage)
                            <li><a href="{{ LaravelLocalization::getLocalizedURL(app()->getLocale(), '/' . $navPage->slug) }}">{{ $navPage->title }}</a></li>
@endforeach
                        <li><a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.all_articles') }}</a></li>
                        <li><a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a></li>
                    </ul>
                </div>

                <div class="mn-footer-links">
                    <h3>{{ __('vela::public.contact_us') }}</h3>
                    <ul>
                        <li>{{ config('app.name', 'Vela CMS') }}</li>
                    </ul>
                </div>
            </div>

            <div class="mn-footer-bottom">
                {{ config('vela.theme.footer_copyright') ?: '© ' . date('Y') . ' ' . config('app.name', 'Vela CMS') . '. ' . __('vela::public.all_rights_reserved') }}
            </div>
        </div>
    </footer>
    @include('vela::templates._partials.scripts-footer')
</body>
</html>
