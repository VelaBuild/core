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

@push('head')
<meta property="article:published_time" content="{{ $metaTags['article_published_time'] }}">
<meta property="article:modified_time" content="{{ $metaTags['article_modified_time'] }}">
<meta property="article:author" content="{{ $metaTags['article_author'] }}">
<meta property="article:section" content="{{ $metaTags['article_section'] }}">
@if($metaTags['article_tags'])
<meta property="article:tag" content="{{ $metaTags['article_tags'] }}">
@endif
@endpush

@section('content')
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
                    <a href="{{ route('vela.public.posts.index') }}" class="ml-4 text-gray-500 hover:text-gray-700">{{ __('vela::public.articles') }}</a>
                </li>
                <li class="flex items-center">
                    <svg class="flex-shrink-0 h-5 w-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="ml-4 text-gray-900 font-medium">{{ Str::limit($post->translated_title, 30) }}</span>
                </li>
            </ol>
        </nav>
    </div>
</section>

<!-- Article Content -->
<section class="py-16 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="max-w-4xl mx-auto">
                <article class="prose prose-lg max-w-none">
                    <!-- Article Header -->
                    <header class="mb-8">
                        <div class="flex items-center mb-4">
                            @if($post->categories->count() > 0)
                            <span class="bg-blue-100 text-blue-800 text-sm font-semibold px-3 py-1 rounded-full">
                                {{ $post->categories->first()->translated_name }}
                            </span>
                            @endif
                            <span class="text-gray-500 text-sm ml-auto">
                                {{ $post->published_at ? $post->published_at->format('M d, Y') : ($post->created_at ? $post->created_at->format('M d, Y') : '') }}
                            </span>
                        </div>

                        <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-6">
                            {{ $post->translated_title }}
                        </h1>

                        @if($post->description)
                        <p class="text-xl text-gray-600 leading-relaxed">
                            {{ $post->translated_description }}
                        </p>
                        @endif
                    </header>

                    <!-- Featured Image -->
                    @if($post->main_image)
                    <div class="mb-8">
                        {!! vela_image($post->main_image->url, $post->title, [600, 1200, 1920], 'fit', ['class' => 'w-full h-64 md:h-96 object-cover rounded-xl shadow-lg']) !!}
                    </div>
                    @endif

                    <!-- Article Content -->
                    <div class="prose prose-lg max-w-none">
                        @if($post->translated_content)
                            @php
                                $content = is_string($post->translated_content) ? json_decode($post->translated_content, true) : $post->translated_content;
                            @endphp
                            @if(isset($content['blocks']) && is_array($content['blocks']))
                                @foreach($content['blocks'] as $block)
                                    @switch($block['type'])
                                        @case('paragraph')
                                            <p class="mb-4 leading-relaxed">{!! renderMarkdown($block['data']['text'] ?? '') !!}</p>
                                            @break
                                        @case('header')
                                            @php
                                                $level = $block['data']['level'] ?? 2;
                                                $tag = 'h' . $level;
                                            @endphp
                                            <{{ $tag }} class="font-bold text-gray-900 mb-4 mt-6 @if($level == 2) text-2xl @elseif($level == 3) text-xl @else text-lg @endif">{!! renderMarkdown($block['data']['text'] ?? '') !!}</{{ $tag }}>
                                            @break
                                        @case('list')
                                            @if(isset($block['data']['items']) && is_array($block['data']['items']))
                                                <ul class="list-disc list-inside mb-4 space-y-2">
                                                    @foreach($block['data']['items'] as $item)
                                                        <li class="leading-relaxed">{!! renderMarkdown($item) !!}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                            @break
                                        @case('image')
                                            @if(isset($block['data']['file']['url']))
                                                <div class="my-6">
                                                    {!! vela_image($block['data']['file']['url'], $block['data']['caption'] ?? '', [400, 800, 1200], 'fit', ['class' => 'w-full rounded-lg shadow-lg']) !!}
                                                    @if(isset($block['data']['caption']) && $block['data']['caption'])
                                                        <p class="text-sm text-gray-600 mt-2 text-center">{{ $block['data']['caption'] }}</p>
                                                    @endif
                                                </div>
                                            @endif
                                            @break
                                        @default
                                            @if(isset($block['data']['text']))
                                                <p class="mb-4 leading-relaxed">{{ $block['data']['text'] }}</p>
                                            @endif
                                    @endswitch
                                @endforeach
                            @else
                                <p class="text-gray-600">{{ trans('vela::global.content_processing') }}</p>
                            @endif
                        @else
                            <p class="text-gray-600">{{ __('vela::public.no_content_available') }}</p>
                        @endif
                    </div>

                    <!-- Article Images -->
                    @if($post->content_images && $post->content_images->count() > 0)
                    <div class="mt-12">
                        <h3 class="text-2xl font-bold text-gray-900 mb-6">{{ __('vela::public.article_images') }}</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach($post->content_images as $image)
                            <div class="aspect-w-16 aspect-h-12">
                                {!! vela_image($image->url, $post->title, [300, 600], 'fit', ['class' => 'w-full h-48 object-cover rounded-lg shadow-lg']) !!}
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </article>

                <!-- Article Meta -->
                <div class="mt-12 bg-gray-50 rounded-xl p-8">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                        <!-- Article Info -->
                        <div>
                            <h3 class="text-lg font-bold text-gray-900 mb-4">{{ __('vela::public.article_information') }}</h3>
                            <div class="space-y-3">
                            @if($post->categories->count() > 0)
                            <div>
                                <span class="text-sm font-medium text-gray-500">{{ __('vela::public.category') }}</span>
                                    <p class="text-gray-900">
                                        <a href="{{ route('vela.public.categories.show', Str::slug($post->categories->first()->name)) }}" class="text-blue-600 hover:text-blue-800 font-medium">
                                            {{ $post->categories->first()->translated_name }}
                                        </a>
                                    </p>
                            </div>
                            @endif

                            <div>
                                <span class="text-sm font-medium text-gray-500">{{ __('vela::public.published') }}</span>
                                <p class="text-gray-900">{{ $post->published_at ? $post->published_at->format('M d, Y') : ($post->created_at ? $post->created_at->format('M d, Y') : '') }}</p>
                            </div>
                            </div>
                        </div>

                        <!-- Share Article -->
                        <div>
                            <h3 class="text-lg font-bold text-gray-900 mb-4">{{ __('vela::public.share_this_article') }}</h3>
                            <p class="text-gray-600 mb-4">
                                {{ __('vela::public.share_article_description') }}
                            </p>
                            <div class="flex space-x-3">
                                <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode(request()->url()) }}" target="_blank" class="flex-1 bg-blue-600 text-white px-4 py-2 rounded-lg text-center font-medium hover:bg-blue-700 transition duration-300">
                                    {{ __('vela::public.share_on_facebook') }}
                                </a>
                                <a href="https://twitter.com/intent/tweet?url={{ urlencode(request()->url()) }}&text={{ urlencode($post->title) }}" target="_blank" class="flex-1 bg-blue-400 text-white px-4 py-2 rounded-lg text-center font-medium hover:bg-blue-500 transition duration-300">
                                    {{ __('vela::public.share_on_twitter') }}
                            </a>
                        </div>
                    </div>

                        <!-- View All Articles -->
                        <div class="flex items-center">
                            <a href="{{ route('vela.public.posts.index') }}" class="btn-premium w-full text-center">
                                {{ __('vela::public.view_all_articles') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Related Articles -->
@if($relatedPosts->count() > 0)
<section class="py-16 bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-gray-900 mb-4">{{ __('vela::public.related_articles') }}</h2>
            <p class="text-lg text-gray-600">
                {{ __('vela::public.related_articles_description') }}
            </p>
        </div>

        <div class="articles-grid">
            @foreach($relatedPosts as $relatedPost)
            <article class="article-card">
                <!-- Article Image -->
                <a href="{{ route('vela.public.posts.show', $relatedPost->slug) }}" class="article-image-link">
                    <div class="article-image-container">
                @if($relatedPost->main_image)
                        {!! vela_image($relatedPost->main_image->url, $relatedPost->translated_title, [300, 600], 'fit', ['class' => 'article-image']) !!}
                @else
                        <div class="article-placeholder">
                            <div class="placeholder-icon">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                    </svg>
                            </div>
                            <p class="placeholder-text">{{ $relatedPost->translated_title }}</p>
                </div>
                @endif
                        <!-- Category Badge -->
                        @if($relatedPost->categories->count() > 0)
                        <div class="category-badge">
                            <span class="badge-text">
                                {{ $relatedPost->categories->first()->translated_name }}
                        </span>
                        </div>
                        @endif
                    </div>
                </a>

                <div class="article-content">
                    <div class="article-meta">
                        <span class="article-date">
                            {{ ($relatedPost->published_at ?? $relatedPost->created_at)->format('M d, Y') }}
                        </span>
                        @if($relatedPost->categories->count() > 0)
                        <a href="{{ route('vela.public.categories.show', Str::slug($relatedPost->categories->first()->name)) }}" class="category-link">
                            {{ $relatedPost->categories->first()->translated_name }}
                        </a>
                        @endif
                    </div>

                    <!-- Article Title -->
                    <h3 class="article-title">
                        <a href="{{ route('vela.public.posts.show', $relatedPost->slug) }}">
                            {{ $relatedPost->translated_title }}
                        </a>
                    </h3>

                    @if($relatedPost->description)
                    <p class="article-description">
                        {{ Str::limit($relatedPost->translated_description, 100) }}
                    </p>
                    @endif

                    <!-- Read Button -->
                    <a href="{{ route('vela.public.posts.show', $relatedPost->slug) }}" class="read-button">
                        <span>{{ __('vela::public.read_article') }}</span>
                        <svg class="button-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                        </svg>
                    </a>
                </div>
            </article>
            @endforeach
        </div>
    </div>
</section>
@endif
@endsection

@php
if (!function_exists('renderMarkdown')) {
    function renderMarkdown($text) {
        // Convert **bold** to <strong>bold</strong>
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);

        // Convert *italic* to <em>italic</em>
        $text = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $text);

        // Convert `code` to <code>code</code>
        $text = preg_replace('/`(.*?)`/', '<code class="bg-gray-100 px-1 py-0.5 rounded text-sm">$1</code>', $text);

        return $text;
    }
}
@endphp
