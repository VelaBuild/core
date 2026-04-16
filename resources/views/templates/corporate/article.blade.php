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
<div class="co-breadcrumb">
    <div class="co-container">
        <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
        <span>/</span>
        <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
        <span>/</span>
        {{ Str::limit($post->translated_title, 40) }}
    </div>
</div>

<!-- Article -->
<article class="co-article">
    <div class="co-container">
        <div class="co-article-layout">
            <!-- Main Content -->
            <div class="co-article-main">
                <header class="co-article-header">
                    @if($post->categories->count() > 0)
                        <span class="co-badge">{{ $post->categories->first()->translated_name }}</span>
                    @endif
                    <h1 class="co-article-title">{{ $post->translated_title }}</h1>
                    @if($post->description)
                        <p class="co-article-subtitle">{{ $post->translated_description }}</p>
                    @endif
                    <div class="co-article-meta">
                        <span class="co-article-date">{{ $post->published_at ? $post->published_at->format('M d, Y') : ($post->created_at ? $post->created_at->format('M d, Y') : '') }}</span>
                        @if($post->categories->count() > 0)
                            <span class="co-article-sep">·</span>
                            <span class="co-article-section">{{ $post->categories->first()->translated_name }}</span>
                        @endif
                    </div>
                </header>

                @if($post->main_image)
                <div class="co-article-featured-img">
                    {!! vela_image($post->main_image->url, $post->translated_title, [600, 1200, 1920], 'fit', ['style' => 'border-radius:6px;width:100%;']) !!}
                </div>
                @endif

                <div class="co-prose">
                    @if($post->translated_content)
                        @php
                            $content = is_string($post->translated_content) ? json_decode($post->translated_content, true) : $post->translated_content;
                        @endphp
                        @if(isset($content['blocks']) && is_array($content['blocks']))
                            @foreach($content['blocks'] as $block)
                                @switch($block['type'])
                                    @case('paragraph')
                                        <p>{!! renderCorporateMarkdown($block['data']['text'] ?? '') !!}</p>
                                        @break
                                    @case('header')
                                        @php
                                            $level = $block['data']['level'] ?? 2;
                                            $tag = 'h' . $level;
                                        @endphp
                                        <{{ $tag }}>{!! renderCorporateMarkdown($block['data']['text'] ?? '') !!}</{{ $tag }}>
                                        @break
                                    @case('list')
                                        @if(isset($block['data']['items']) && is_array($block['data']['items']))
                                            <ul>
                                                @foreach($block['data']['items'] as $item)
                                                    <li>{!! renderCorporateMarkdown($item) !!}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        @break
                                    @case('image')
                                        @if(isset($block['data']['file']['url']))
                                            <figure>
                                                {!! vela_image($block['data']['file']['url'], $block['data']['caption'] ?? '', [400, 800, 1200], 'fit', []) !!}
                                                @if(isset($block['data']['caption']) && $block['data']['caption'])
                                                    <figcaption>{{ $block['data']['caption'] }}</figcaption>
                                                @endif
                                            </figure>
                                        @endif
                                        @break
                                    @default
                                        @if(isset($block['data']['text']))
                                            <p>{{ $block['data']['text'] }}</p>
                                        @endif
                                @endswitch
                            @endforeach
                        @else
                            <p style="color:#6b7280;">{{ trans('vela::global.content_processing') }}</p>
                        @endif
                    @else
                        <p style="color:#6b7280;">{{ __('vela::public.no_content_available') }}</p>
                    @endif

                    <!-- Article Images -->
                    @if($post->content_images && $post->content_images->count() > 0)
                    <div style="margin-top:40px;">
                        <h3>{{ __('vela::public.article_images') }}</h3>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-top:16px;">
                            @foreach($post->content_images as $image)
                                {!! vela_image($image->url, $post->translated_title, [300, 600], 'fit', ['style' => 'border-radius:6px;width:100%;height:160px;object-fit:cover;']) !!}
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>

                <!-- Share -->
                <div class="co-share">
                    <span class="co-share-label">{{ __('vela::public.share') }}:</span>
                    <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode(request()->url()) }}" target="_blank" class="co-share-btn co-share-fb">{{ __('vela::public.share_on_facebook') }}</a>
                    <a href="https://twitter.com/intent/tweet?url={{ urlencode(request()->url()) }}&text={{ urlencode($post->translated_title) }}" target="_blank" class="co-share-btn co-share-tw">{{ __('vela::public.share_on_twitter') }}</a>
                </div>
            </div>

            <!-- Sidebar -->
            <aside class="co-sidebar">
                @if($post->categories->count() > 0)
                <div class="co-sidebar-widget">
                    <h4 class="co-sidebar-title">{{ __('vela::public.topics') }}</h4>
                    <div class="co-sidebar-chips">
                        @foreach($post->categories as $cat)
                            <a href="{{ route('vela.public.categories.show', Str::slug($cat->name)) }}" class="co-chip co-chip-sm">{{ $cat->translated_name }}</a>
                        @endforeach
                    </div>
                </div>
                @endif
            </aside>
        </div>
    </div>
</article>

<!-- Related Articles -->
@if($relatedPosts->count() > 0)
<section class="co-section co-section-alt">
    <div class="co-container">
        <div class="co-section-header" style="justify-content:center;text-align:center;">
            <div>
                <h2 class="co-section-title">{{ __('vela::public.related_articles') }}</h2>
                <p class="co-section-subtitle">{{ __('vela::public.related_articles_description') }}</p>
            </div>
        </div>

        <div class="co-grid co-grid-3">
            @foreach($relatedPosts as $relatedPost)
            <a href="{{ route('vela.public.posts.show', $relatedPost->slug) }}" class="co-card">
                <div class="co-card-img-wrap">
                    @if($relatedPost->main_image)
                        {!! vela_image($relatedPost->main_image->url, $relatedPost->translated_title, [300, 600], 'fit', ['class' => 'co-card-img']) !!}
                    @else
                        <div class="co-placeholder">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        </div>
                    @endif
                </div>
                <div class="co-card-body">
                    <div class="co-card-meta">
                        <span class="co-card-date">{{ ($relatedPost->published_at ?? $relatedPost->created_at)->format('M d, Y') }}</span>
                        @if($relatedPost->categories->count() > 0)
                            <span class="co-card-category">{{ $relatedPost->categories->first()->translated_name }}</span>
                        @endif
                    </div>
                    <h3 class="co-card-title">{{ $relatedPost->translated_title }}</h3>
                    @if($relatedPost->description)
                        <p class="co-card-desc">{{ Str::limit($relatedPost->translated_description, 100) }}</p>
                    @endif
                </div>
            </a>
            @endforeach
        </div>
    </div>
</section>
@endif
@endsection

@php
if (!function_exists('renderCorporateMarkdown')) {
    function renderCorporateMarkdown($text) {
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/`(.*?)`/', '<code>$1</code>', $text);
        return $text;
    }
}
@endphp
