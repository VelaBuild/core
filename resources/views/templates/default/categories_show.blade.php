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
            <h1 class="text-4xl md:text-5xl font-bold mb-4">{{ $category->translated_name }} {{ __('vela::public.articles') }}</h1>
            <p class="text-xl text-gray-300 max-w-2xl mx-auto">
                {{ __('vela::public.discover_our') }} {{ $category->translated_name }} {{ __('vela::public.articles') }} {{ __('vela::public.and_find_comprehensive_guides') }}.
            </p>
        </div>
    </div>
</section>

<!-- Breadcrumb -->
<section class="bg-gray-100 py-4">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-4">
                <li class="flex items-center">
                    <a href="{{ route('vela.public.home') }}" class="text-gray-500 hover:text-gray-700">{{ __('vela::public.home') }}</a>
                </li>
                <li class="flex items-center">
                    <svg class="flex-shrink-0 h-5 w-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                    </svg>
                    <a href="{{ route('vela.public.categories.index') }}" class="ml-4 text-gray-500 hover:text-gray-700">{{ __('vela::public.topics') }}</a>
                </li>
                <li class="flex items-center">
                    <svg class="flex-shrink-0 h-5 w-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="ml-4 text-gray-900 font-medium">{{ $category->translated_name }}</span>
                </li>
            </ol>
        </nav>
    </div>
</section>

<!-- Articles Grid -->
<section class="py-16 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        @if($posts->count() > 0)
        <div class="mb-8">
            <p class="text-lg text-gray-600">
                {{ __('vela::public.showing_category_articles', ['count' => $posts->count(), 'category' => $category->translated_name]) }}
            </p>
        </div>

        <div class="articles-grid">
            @foreach($posts as $post)
            <article class="article-card">
                <!-- Article Image -->
                <a href="{{ route('vela.public.posts.show', $post->slug) }}" class="article-image-link">
                    <div class="article-image-container">
                        @if($post->main_image)
                        {!! vela_image($post->main_image->url, $post->translated_title, [300, 600, 900], 'fit', ['class' => 'article-image']) !!}
                        @else
                        <div class="article-placeholder">
                            <div class="placeholder-icon">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                            </div>
                            <p class="placeholder-text">{{ $post->translated_title }}</p>
                        </div>
                        @endif
                    </div>
                </a>

                <div class="article-content">
                    <div class="article-meta">
                        <span class="article-date">
                            {{ ($post->published_at ?? $post->created_at)->format('M d, Y') }}
                        </span>
                        <a href="{{ route('vela.public.categories.show', Str::slug($category->name)) }}" class="category-link">
                            {{ $category->translated_name }}
                        </a>
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

        <!-- Pagination -->
        @if($posts->hasPages())
        <div class="mt-12">
            {{ $posts->links() }}
        </div>
        @endif

        @else
        <div class="text-center py-16">
            <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
            </svg>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">{{ __('vela::public.no_articles_found') }}</h3>
            <p class="text-gray-600 mb-6">{{ __('vela::public.no_articles_in_category', ['category' => $category->translated_name]) }}</p>
            <a href="{{ route('vela.public.categories.index') }}" class="btn-premium">
                {{ __('vela::public.browse_other_categories') }}
            </a>
        </div>
        @endif
    </div>
</section>

<!-- Related Categories -->
@if($categories->count() > 1)
<section class="py-16 bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-gray-900 mb-4">{{ __('vela::public.other_categories') }}</h2>
            <p class="text-lg text-gray-600">
                {{ __('vela::public.explore_articles_in_other_categories') }}
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            @foreach($categories->where('id', '!=', $category->id)->take(4) as $otherCategory)
            <a href="{{ route('vela.public.categories.show', Str::slug($otherCategory->name)) }}" class="group bg-white rounded-xl p-6 shadow-lg hover:shadow-xl transition duration-300">
                <div class="text-center">
                    @if($otherCategory->image)
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full overflow-hidden">
                        {!! vela_image($otherCategory->image->url, $otherCategory->name, [200, 400, 800], 'crop', ['class' => 'w-full h-full object-cover']) !!}
                    </div>
                    @else
                    <div class="w-16 h-16 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                        @if($otherCategory->icon)
                        <i class="{{ $otherCategory->icon }} text-2xl text-gray-600"></i>
                        @else
                        <svg class="w-8 h-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                        @endif
                    </div>
                    @endif

                    <h3 class="text-lg font-semibold text-gray-900 group-hover:text-blue-600 transition duration-300">
                        {{ $otherCategory->translated_name }}
                    </h3>

                    <p class="text-sm text-gray-500 mt-2">
                        {{ trans_choice('vela::public.articles_count', $otherCategory->contents()->where('status', 'published')->count(), ['count' => $otherCategory->contents()->where('status', 'published')->count()]) }}
                    </p>
                </div>
            </a>
            @endforeach
        </div>
    </div>
</section>
@endif
@endsection
