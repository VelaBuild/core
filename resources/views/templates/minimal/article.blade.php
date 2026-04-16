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
<div class="mn-breadcrumb">
    <div class="mn-container">
        <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
        <span>/</span>
        <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
        <span>/</span>
        {{ Str::limit($post->translated_title, 30) }}
    </div>
</div>

<!-- Article -->
<article>
    <div class="mn-article-header">
        <div class="mn-narrow">
            @if($post->categories->count() > 0)
                <span class="mn-badge">{{ $post->categories->first()->translated_name }}</span>
            @endif
            <h1>{{ $post->translated_title }}</h1>
            @if($post->description)
                <p class="mn-subtitle">{{ $post->translated_description }}</p>
            @endif
            <p class="mn-date">{{ $post->published_at ? $post->published_at->format('M d, Y') : ($post->created_at ? $post->created_at->format('M d, Y') : '') }}</p>
        </div>
    </div>

    @if($post->main_image)
    <div class="mn-article-img">
        <div class="mn-container">
            {!! vela_image($post->main_image->url, $post->title, [600, 1200, 1920], 'fit', ['style' => 'border-radius:8px']) !!}
        </div>
    </div>
    @endif

    <div class="mn-narrow mn-prose" style="padding-top:32px;padding-bottom:32px;">
        @if($post->translated_content)
            @php
                $content = is_string($post->translated_content) ? json_decode($post->translated_content, true) : $post->translated_content;
            @endphp
            @if(isset($content['blocks']) && is_array($content['blocks']))
                @foreach($content['blocks'] as $block)
                    @switch($block['type'])
                        @case('paragraph')
                            <p>{!! renderMarkdown($block['data']['text'] ?? '') !!}</p>
                            @break
                        @case('header')
                            @php
                                $level = $block['data']['level'] ?? 2;
                                $tag = 'h' . $level;
                            @endphp
                            <{{ $tag }}>{!! renderMarkdown($block['data']['text'] ?? '') !!}</{{ $tag }}>
                            @break
                        @case('list')
                            @if(isset($block['data']['items']) && is_array($block['data']['items']))
                                <ul>
                                    @foreach($block['data']['items'] as $item)
                                        <li>{!! renderMarkdown($item) !!}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @break
                        @case('image')
                            @if(isset($block['data']['file']['url']))
                                <figure>
                                    {!! vela_image($block['data']['file']['url'], $block['data']['caption'] ?? '', [400, 800, 1200], 'fit', []) !!}
                                    @if(isset($block['data']['caption']) && $block['data']['caption'])
                                        <figcaption style="text-align:center;font-size:0.85rem;color:#888;margin-top:8px;">{{ $block['data']['caption'] }}</figcaption>
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
                <p style="color:#666;">{{ trans('vela::global.content_processing') }}</p>
            @endif
        @else
            <p style="color:#666;">{{ __('vela::public.no_content_available') }}</p>
        @endif

        <!-- Article Images -->
        @if($post->content_images && $post->content_images->count() > 0)
        <div style="margin-top:40px;">
            <h3>{{ __('vela::public.article_images') }}</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-top:16px;">
                @foreach($post->content_images as $image)
                    {!! vela_image($image->url, $post->title, [300, 600], 'fit', ['style' => 'border-radius:8px;width:100%;height:160px;object-fit:cover;']) !!}
                @endforeach
            </div>
        </div>
        @endif

        <!-- Share -->
        <div class="mn-share">
            <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode(request()->url()) }}" target="_blank" class="mn-share-fb">{{ __('vela::public.share_on_facebook') }}</a>
            <a href="https://twitter.com/intent/tweet?url={{ urlencode(request()->url()) }}&text={{ urlencode($post->title) }}" target="_blank" class="mn-share-tw">{{ __('vela::public.share_on_twitter') }}</a>
        </div>
    </div>
</article>

<!-- Related Articles -->
@if($relatedPosts->count() > 0)
<section class="mn-section mn-section-alt">
    <div class="mn-container">
        <div class="mn-section-header" style="text-align:center;">
            <h2>{{ __('vela::public.related_articles') }}</h2>
            <p>{{ __('vela::public.related_articles_description') }}</p>
        </div>

        <div class="mn-grid">
            @foreach($relatedPosts as $relatedPost)
            <a href="{{ route('vela.public.posts.show', $relatedPost->slug) }}" class="mn-card">
                <div class="mn-card-img">
                    @if($relatedPost->main_image)
                        {!! vela_image($relatedPost->main_image->url, $relatedPost->translated_title, [300, 600], 'fit', []) !!}
                    @else
                        <div class="mn-placeholder">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        </div>
                    @endif
                </div>
                <div class="mn-card-body">
                    <div class="mn-card-meta">
                        <span>{{ ($relatedPost->published_at ?? $relatedPost->created_at)->format('M d, Y') }}</span>
                        @if($relatedPost->categories->count() > 0)
                            <span>{{ $relatedPost->categories->first()->translated_name }}</span>
                        @endif
                    </div>
                    <h3 class="mn-card-title">{{ $relatedPost->translated_title }}</h3>
                    @if($relatedPost->description)
                        <p class="mn-card-desc">{{ Str::limit($relatedPost->translated_description, 100) }}</p>
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
if (!function_exists('renderMarkdown')) {
    function renderMarkdown($text) {
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/`(.*?)`/', '<code>$1</code>', $text);
        return $text;
    }
}
@endphp
