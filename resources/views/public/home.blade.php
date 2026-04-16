@extends(vela_template_layout())

@section('title', $metaTags['title'])
@section('description', $metaTags['description'])
@section('keywords', $metaTags['keywords'])
@section('og_type', $metaTags['og_type'])
@section('og_title', $metaTags['og_title'])
@section('og_description', $metaTags['og_description'])
@section('og_image', $metaTags['og_image'])
@section('og_image_alt', $metaTags['og_image_alt'])
@section('twitter_title', $metaTags['twitter_title'])
@section('twitter_description', $metaTags['twitter_description'])
@section('twitter_image', $metaTags['twitter_image'])
@section('twitter_image_alt', $metaTags['twitter_image_alt'])
@section('canonical_url', $metaTags['canonical_url'])

@section('content')
<!-- Hero Section with Hero Image -->
<section class="relative min-h-screen flex items-center justify-center overflow-hidden">
    <!-- Hero Background Image -->
    <div class="absolute inset-0">
        <img src="{{ asset('images/hero.png') }}" alt="Hero Image" class="w-full h-full object-cover">
        <div class="absolute inset-0 bg-gradient-to-br from-black/40 via-black/20 to-black/60"></div>
    </div>

    <!-- Floating Elements -->
    <div class="absolute inset-0 floating-elements"></div>

    <div class="relative z-10 container-premium text-center">
        <div class="max-w-5xl mx-auto">
            <h1 class="heading-premium text-5xl md:text-7xl lg:text-8xl mb-8 text-white hero-text-shadow">
                {{ app(\VelaBuild\Core\Services\SiteContext::class)->getName() }}
            </h1>
            <p class="text-white text-xl md:text-2xl mb-12 max-w-3xl mx-auto leading-relaxed opacity-90 hero-text-shadow">
                {{ app(\VelaBuild\Core\Services\SiteContext::class)->getSiteDescription() }}
            </p>
            <div class="flex flex-col sm:flex-row gap-6 justify-center items-center">
                <a href="{{ route('vela.public.posts.index') }}" class="btn-premium">
                    {{ __('vela::public.articles') }}
                </a>
                <a href="{{ route('vela.public.categories.index') }}" class="btn-outline-premium bg-white/10 border-white/30 text-white hover:bg-white hover:text-blue-600">
                    {{ __('vela::public.browse_topics') }}
                </a>
            </div>
        </div>
    </div>

    <!-- Scroll indicator -->
    <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 animate-bounce">
        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
        </svg>
    </div>
</section>

<!-- Latest Articles Section -->
@if($latestPosts->count() > 0)
<section class="section-padding bg-gradient-ocean">
    <div class="container-premium">
        <div class="flex justify-between items-end mb-20">
            <div>
                <h2 class="heading-premium text-4xl md:text-5xl mb-6">
                    {{ __('vela::public.latest_articles') }} <span class="text-gradient">{{ __('vela::public.insights') }}</span>
                </h2>
                <p class="text-premium text-xl max-w-2xl">
                    {{ __('vela::public.latest_articles_description') }}
                </p>
            </div>
            <a href="{{ route('vela.public.posts.index') }}" class="hidden md:block btn-outline-premium">
                {{ __('vela::public.view_all_articles') }}
            </a>
        </div>

        <div class="articles-grid">
            @foreach($latestPosts as $post)
            <article class="article-card">
                <!-- Article Image -->
                <a href="{{ route('vela.public.posts.show', $post->slug) }}" class="article-image-link">
                    <div class="article-image-container">
                        @if($post->main_image)
                        <img src="{{ $post->main_image->url }}" alt="{{ $post->title }}" class="article-image">
                        @else
                        <div class="article-placeholder">
                            <div class="placeholder-icon">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                            </div>
                            <p class="placeholder-text">{{ $post->title }}</p>
                        </div>
                        @endif

                    </div>
                </a>

                <div class="article-content">
                    <div class="article-meta">
                        <span class="article-date">
                            {{ ($post->published_at ?? $post->created_at)->format('M d, Y') }}
                        </span>
                        @if($post->categories->count() > 0)
                        <a href="{{ route('vela.public.categories.show', Str::slug($post->categories->first()->name)) }}" class="category-link">
                            {{ $post->categories->first()->translated_name }}
                        </a>
                        @endif
                    </div>

                    <!-- Article Title -->
                    <h3 class="article-title">
                        <a href="{{ route('vela.public.posts.show', $post->slug) }}">
                            {{ $post->translated_title }}
                        </a>
                    </h3>

                    @if($post->description)
                    <p class="article-description">
                        {{ Str::limit($post->translated_description, 100) }}
                    </p>
                    @endif

                    <!-- Read Button -->
                    <a href="{{ route('vela.public.posts.show', $post->slug) }}" class="read-button">
                        <span>{{ __('vela::public.read_article') }}</span>
                        <svg class="button-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                        </svg>
                    </a>
                </div>
            </article>
            @endforeach
        </div>

        <div class="text-center mt-12 md:hidden">
            <a href="{{ route('vela.public.posts.index') }}" class="btn-premium">
                {{ __('vela::public.view_all_articles') }}
            </a>
        </div>
    </div>
</section>
@endif

<!-- Topics Section -->
@if($categories->count() > 0)
<section class="section-padding bg-white">
    <div class="container-premium">
        <div class="text-center mb-20">
            <h2 class="heading-premium text-4xl md:text-5xl mb-6">
                {{ __('vela::public.explore') }} <span class="text-gradient">{{ __('vela::public.topics') }}</span>
            </h2>
            <p class="text-premium text-xl max-w-2xl mx-auto">
                {{ __('vela::public.topics_description') }}
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
            @foreach($categories as $category)
            <a href="{{ route('vela.public.categories.show', Str::slug($category->name)) }}" class="group">
                <div class="content-card rounded-2xl overflow-hidden hover-lift">
                    <!-- Category Featured Image - Square -->
                    <div class="relative aspect-square overflow-hidden">
                        @if($category->image)
                        <img src="{{ $category->image->url }}" alt="{{ $category->name }}" class="w-full h-full object-cover image-zoom">
                        @else
                        <div class="w-full h-full bg-gradient-to-br from-blue-100 to-blue-200 flex items-center justify-center">
                            @if($category->icon)
                            <i class="{{ $category->icon }} text-6xl text-blue-600"></i>
                            @else
                            <svg class="w-16 h-16 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                            @endif
                        </div>
                        @endif
                        <!-- Overlay -->
                        <div class="absolute inset-0 gradient-overlay"></div>
                    </div>

                    <!-- Category Content - Simplified -->
                    <div class="p-6 text-center">
                        <h3 class="heading-premium text-xl mb-2 group-hover:text-blue-600 transition-colors duration-300">
                            {{ $category->translated_name }}
                        </h3>

                        <p class="text-sm text-gray-500">
                            {{ trans_choice('vela::public.articles_count', $category->contents()->where('status', 'published')->count(), ['count' => $category->contents()->where('status', 'published')->count()]) }}
                        </p>
                    </div>
                </div>
            </a>
            @endforeach
        </div>
    </div>
</section>
@endif


<!-- CTA Section -->
<section class="section-padding bg-gradient-to-br from-blue-50 to-blue-100">
    <div class="container-premium text-center">
        <h2 class="heading-premium text-4xl md:text-5xl mb-8">
            {{ __('vela::public.ready_to_discover') }} <span class="text-gradient">{{ __('vela::public.discover_thailand') }}</span>
        </h2>
        <p class="text-premium text-xl mb-12 max-w-3xl mx-auto">
            {{ __('vela::public.cta_description') }}
        </p>
        <div class="flex flex-col sm:flex-row gap-6 justify-center">
            <a href="{{ route('vela.public.posts.index') }}" class="btn-premium">
                {{ __('vela::public.start_reading') }}
            </a>
            <a href="{{ route('vela.public.categories.index') }}" class="btn-outline-premium">
                {{ __('vela::public.browse_topics') }}
            </a>
        </div>
    </div>
</section>
@endsection
