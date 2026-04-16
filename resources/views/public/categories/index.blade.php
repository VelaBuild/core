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
<!-- Page Header -->
<section class="bg-gray-900 text-white py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center">
            <h1 class="text-4xl md:text-5xl font-bold mb-4">{{ __('vela::public.article_categories') }}</h1>
            <p class="text-xl text-gray-300 max-w-2xl mx-auto">
                {{ __('vela::public.explore_article_categories_description') }}
            </p>
        </div>
    </div>
</section>

<!-- Categories Grid -->
<section class="py-16 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        @if($categories->count() > 0)
        <div class="topics-grid">
            @foreach($categories as $category)
            <a href="{{ route('vela.public.categories.show', Str::slug($category->name)) }}" class="topic-card">
                <div class="topic-image-container">
                    @if($category->image)
                    <img src="{{ $category->image->url }}" alt="{{ $category->name }}" class="topic-image">
                    @else
                    <div class="topic-placeholder">
                        <div class="placeholder-icon">
                            @if($category->icon)
                            <i class="{{ $category->icon }} text-2xl text-white"></i>
                            @else
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                            @endif
                        </div>
                    </div>
                    @endif
                </div>

                <div class="topic-content">
                    <h3 class="topic-title">
                        {{ $category->translated_name }}
                    </h3>

                    <p class="topic-count">
                        {{ trans_choice('vela::public.articles_count', $category->contents()->where('status', 'published')->count(), ['count' => $category->contents()->where('status', 'published')->count()]) }}
                    </p>
                </div>
            </a>
            @endforeach
        </div>
        @else
        <div class="text-center py-16">
            <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
            </svg>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">{{ __('vela::public.no_categories_available') }}</h3>
            <p class="text-gray-600">{{ __('vela::public.check_back_later_categories') }}</p>
        </div>
        @endif
    </div>
</section>

<!-- CTA Section -->
<section class="py-16 bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <h2 class="text-3xl font-bold text-gray-900 mb-4">
            {{ __('vela::public.cant_find_what_youre_looking_for') }}
        </h2>
        <p class="text-lg text-gray-600 mb-8 max-w-2xl mx-auto">
            {{ __('vela::public.browse_all_articles_or_contact_us') }}
        </p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ route('vela.public.posts.index') }}" class="btn-premium">
                {{ __('vela::public.view_all_articles') }}
            </a>
            <a href="{{ route('vela.public.home') }}" class="btn-outline-premium">
                {{ __('vela::public.back_to_home') }}
            </a>
        </div>
    </div>
</section>
@endsection
