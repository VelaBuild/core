<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'Vela CMS'))</title>
    <meta name="description" content="@yield('description', '')">

    <!-- Additional Meta Tags -->
    <meta name="keywords" content="@yield('keywords', '')">
    <meta name="author" content="{{ config('app.name', 'Vela CMS') }}">
    <meta name="robots" content="{{ config('vela.visibility.mode') === 'restricted' && config('vela.visibility.noindex') ? 'noindex, nofollow' : 'index, follow' }}">
    <meta name="language" content="{{ str_replace('_', '-', app()->getLocale()) }}">
    <meta name="revisit-after" content="7 days">

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

    <!-- Additional SEO Meta Tags -->
    <meta name="theme-color" content="#1f2937">
    <meta name="msapplication-TileColor" content="#1f2937">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Vela CMS') }}">

    <!-- Canonical URL -->
    <link rel="canonical" href="@yield('canonical_url', request()->url())">

    <!-- Language Href Tags -->
    @foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
        <link rel="alternate" hreflang="{{ $localeCode }}" href="{{ LaravelLocalization::getLocalizedURL($localeCode) }}" />
    @endforeach

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=playfair+display:400,500,600,700|inter:300,400,500,600,700&display=swap" rel="stylesheet" />

    <!-- Styles -->
    <link href="{{ asset('vendor/vela/css/premium.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/vela/css/page-blocks.css') }}" rel="stylesheet">

    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

    @include('vela::templates._partials.analytics')
</head>
<body class="antialiased">
    <!-- Premium Navigation -->
    <nav class="nav-premium" id="navbar">
        <div class="container-premium">
            <div class="flex justify-between items-center h-20">
                <div class="flex items-center">
                    <a href="{{ route('vela.public.home') }}" class="flex items-center space-x-3">
                        <img src="{{ asset(config('vela.theme.logo_image', 'images/logo.png')) }}" alt="{{ config('app.name', 'Vela CMS') }}" class="h-12 w-auto">

                    </a>
                </div>

                <div class="hidden md:flex items-center space-x-8">
                    <a href="{{ route('vela.public.home') }}" class="text-gray-700 hover:text-blue-600 font-medium transition-colors duration-300">{{ __('vela::public.home') }}</a>
                    @php
                        $navPages = \VelaBuild\Core\Models\Page::where('locale', app()->getLocale())->where('status', 'published')->whereNull('parent_id')->orderBy('order_column')->get();
                    @endphp
                    @foreach($navPages as $navPage)
                        <a href="{{ LaravelLocalization::getLocalizedURL(app()->getLocale(), '/' . $navPage->slug) }}" class="text-gray-700 hover:text-blue-600 font-medium transition-colors duration-300">{{ $navPage->title }}</a>
                    @endforeach
                    <a href="{{ route('vela.public.posts.index') }}" class="text-gray-700 hover:text-blue-600 font-medium transition-colors duration-300">{{ __('vela::public.articles') }}</a>
                    <a href="{{ route('vela.public.categories.index') }}" class="text-gray-700 hover:text-blue-600 font-medium transition-colors duration-300">{{ __('vela::public.topics') }}</a>

                    <!-- Language Switcher -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="language-switcher-btn">
                            @php
                                $currentLocale = app()->getLocale();
                                $flagMap = [
                                    'en' => 'gb',
                                    'th' => 'th',
                                    'zh-Hans' => 'cn',
                                    'de' => 'de',
                                    'nl' => 'nl',
                                    'fr' => 'fr',
                                    'it' => 'it',
                                    'dk' => 'dk',
                                    'ru' => 'ru',
                                    'ar' => 'sa'
                                ];
                                $currentFlag = $flagMap[$currentLocale] ?? 'gb';
                            @endphp
                            <img src="{{ asset('flags/1x1/' . $currentFlag . '.svg') }}" alt="{{ $currentLocale }}" class="language-flag">
                            <span class="language-code">{{ strtoupper($currentLocale) }}</span>
                            <svg class="language-chevron" :class="{'rotate-180': open}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>

                        <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" class="language-dropdown">
                            <div class="language-dropdown-content">
                                @foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
                                    @php
                                        $flagCode = $flagMap[$localeCode] ?? 'gb';
                                    @endphp
                                    <a href="{{ LaravelLocalization::getLocalizedURL($localeCode) }}"
                                       class="language-option {{ app()->getLocale() == $localeCode ? 'language-option-active' : '' }}">
                                        <img src="{{ asset('flags/1x1/' . $flagCode . '.svg') }}" alt="{{ $localeCode }}" class="language-flag-small">
                                        <span class="language-option-text">{{ $properties['native'] }}</span>
                                        @if(app()->getLocale() == $localeCode)
                                            <svg class="language-check" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                            </svg>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mobile Language Switcher -->
                <div class="md:hidden flex items-center space-x-4">
                    <!-- Mobile Language Switcher -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="language-switcher-btn-mobile">
                            @php
                                $currentLocale = app()->getLocale();
                                $flagMap = [
                                    'en' => 'gb',
                                    'th' => 'th',
                                    'zh-Hans' => 'cn',
                                    'ar' => 'sa',
                                    'de' => 'de',
                                    'fr' => 'fr',
                                    'it' => 'it',
                                    'nl' => 'nl',
                                    'ru' => 'ru',
                                    'dk' => 'dk'
                                ];
                                $currentFlag = $flagMap[$currentLocale] ?? 'gb';
                            @endphp
                            <img src="{{ asset('flags/1x1/' . $currentFlag . '.svg') }}" alt="{{ $currentLocale }}" class="language-flag-mobile">
                            <span class="language-code-mobile">{{ strtoupper($currentLocale) }}</span>
                        </button>

                        <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" class="language-dropdown-mobile">
                            <div class="language-dropdown-content">
                                @foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
                                    @php
                                        $flagCode = $flagMap[$localeCode] ?? 'gb';
                                    @endphp
                                    <a href="{{ LaravelLocalization::getLocalizedURL($localeCode) }}"
                                       class="language-option-mobile {{ app()->getLocale() == $localeCode ? 'language-option-active' : '' }}">
                                        <img src="{{ asset('flags/1x1/' . $flagCode . '.svg') }}" alt="{{ $localeCode }}" class="language-flag-small">
                                        <span class="language-option-text-mobile">{{ $properties['native'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <!-- Mobile menu button -->
                    <button class="text-gray-700 hover:text-blue-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="pt-20">
        @yield('content')
    </main>

    <!-- Premium Footer -->
    <footer class="footer-premium">
        <div class="container-premium">
            <div class="py-20">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-12">
                    <div class="col-span-1 md:col-span-2">
                        <div class="flex items-center space-x-3 mb-6">
                            <img src="{{ asset(config('vela.theme.logo_image', 'images/logo.png')) }}" alt="{{ config('app.name', 'Vela CMS') }}" class="h-10 w-auto">
                            <div>
                                <h3 class="text-xl font-bold text-white">{{ config('app.name', 'Vela CMS') }}</h3>
                            </div>
                        </div>
                        <p class="text-gray-300 mb-6 max-w-md">
                            {{ config('vela.site.tagline', '') }}
                        </p>
                        <div class="flex space-x-4">

                        </div>
                    </div>

                    <div class="pl-4 ">
                        <h4 class="text-lg font-semibold text-white mb-6">{{ __('vela::public.quick_links') }}</h4>
                        <ul class="space-y-3" style="list-style-type: none;">
                            <li><a href="{{ route('vela.public.home') }}" class="text-white hover:text-blue-300 transition-colors duration-300">{{ __('vela::public.home') }}</a></li>
                            @foreach($navPages as $navPage)
                                <li><a href="{{ LaravelLocalization::getLocalizedURL(app()->getLocale(), '/' . $navPage->slug) }}" class="text-white hover:text-blue-300 transition-colors duration-300">{{ $navPage->title }}</a></li>
                            @endforeach
                            <li><a href="{{ route('vela.public.posts.index') }}" class="text-white hover:text-blue-300 transition-colors duration-300">{{ __('vela::public.all_articles') }}</a></li>
                            <li><a href="{{ route('vela.public.categories.index') }}" class="text-white hover:text-blue-300 transition-colors duration-300">{{ __('vela::public.topics') }}</a></li>
                        </ul>
                    </div>

                    <div>
                        <h4 class="text-lg font-semibold text-white mb-6">{{ __('vela::public.contact_us') }}</h4>
                        <div class="space-y-3">
                            @yield('footer_contact')
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-700 mt-12 pt-8 text-center">
                    <p class="text-gray-400">{{ config('vela.theme.footer_copyright') ?: '&copy; ' . date('Y') . ' ' . config('app.name', 'Vela CMS') . '. ' . __('vela::public.all_rights_reserved') }}</p>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.getElementById('navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>

    @include('vela::partials.cookie-consent')
</body>
</html>
