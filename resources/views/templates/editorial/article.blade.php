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
<div class="ed-breadcrumb">
    <div class="ed-container">
        <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
        <span class="ed-breadcrumb-sep">/</span>
        <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
        <span class="ed-breadcrumb-sep">/</span>
        {{ Str::limit($post->translated_title, 50) }}
    </div>
</div>

<!-- Article -->
<article>
    <div class="ed-container">
        <div class="ed-article-layout">
            <!-- Main content -->
            <div>
                <header class="ed-article-header">
                    @if($post->categories->count() > 0)
                        <a href="{{ route('vela.public.categories.show', Str::slug($post->categories->first()->name)) }}" class="ed-article-kicker">{{ $post->categories->first()->translated_name }}</a>
                    @endif
                    <h1>{{ $post->translated_title }}</h1>
                    <div class="ed-article-meta">
                        <time>{{ ($post->published_at ?? $post->created_at)->format('F j, Y') }}</time>
                        @if($post->categories->count() > 0)
                            <span class="ed-meta-dot">·</span>
                            <a href="{{ route('vela.public.categories.show', Str::slug($post->categories->first()->name)) }}">{{ $post->categories->first()->translated_name }}</a>
                        @endif
                    </div>
                </header>

                @if($post->main_image)
                <div class="ed-article-featured-img">
                    {!! vela_image($post->main_image->url, $post->translated_title, [600, 1200, 1920], 'fit', ['style' => 'width:100%;border-radius:3px;']) !!}
                </div>
                @endif

                <div class="ed-prose">
                    @if($post->translated_content)
                        @php
                            $content = is_string($post->translated_content) ? json_decode($post->translated_content, true) : $post->translated_content;
                        @endphp
                        @if(isset($content['blocks']) && is_array($content['blocks']))
                            @foreach($content['blocks'] as $block)
                                @switch($block['type'])
                                    @case('paragraph')
                                        <p>{!! renderEditorialMarkdown($block['data']['text'] ?? '') !!}</p>
                                        @break
                                    @case('header')
                                        @php $level = $block['data']['level'] ?? 2; $tag = 'h' . $level; @endphp
                                        <{{ $tag }}>{!! renderEditorialMarkdown($block['data']['text'] ?? '') !!}</{{ $tag }}>
                                        @break
                                    @case('list')
                                        @if(isset($block['data']['items']) && is_array($block['data']['items']))
                                            <ul>
                                                @foreach($block['data']['items'] as $item)
                                                    <li>{!! renderEditorialMarkdown($item) !!}</li>
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
                                    @case('quote')
                                        <blockquote>
                                            <p>{!! renderEditorialMarkdown($block['data']['text'] ?? '') !!}</p>
                                            @if(isset($block['data']['caption']) && $block['data']['caption'])
                                                <cite>{{ $block['data']['caption'] }}</cite>
                                            @endif
                                        </blockquote>
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

                    <!-- Page blocks -->
                    @if($post->contentBlocks && $post->contentBlocks->count() > 0)
                        @include(template_view('page_blocks'), ['blocks' => $post->contentBlocks])
                    @endif

                    <!-- Additional content images -->
                    @if($post->content_images && $post->content_images->count() > 0)
                    <div style="margin-top:40px;">
                        <h3>{{ __('vela::public.article_images') }}</h3>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-top:16px;">
                            @foreach($post->content_images as $image)
                                {!! vela_image($image->url, $post->translated_title, [300, 600], 'fit', ['style' => 'border-radius:3px;width:100%;height:160px;object-fit:cover;']) !!}
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>

                <!-- Share -->
                <div class="ed-share">
                    <span class="ed-share-label">{{ __('vela::public.share') }}:</span>
                    <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode(request()->url()) }}" target="_blank" rel="noopener" class="ed-share-btn">{{ __('vela::public.share_on_facebook') }}</a>
                    <a href="https://twitter.com/intent/tweet?url={{ urlencode(request()->url()) }}&text={{ urlencode($post->translated_title) }}" target="_blank" rel="noopener" class="ed-share-btn">{{ __('vela::public.share_on_twitter') }}</a>
                </div>
            </div>

            <!-- Sidebar -->
            <aside class="ed-sidebar">
                @if($post->categories->count() > 0)
                <div class="ed-sidebar-widget">
                    <h4>{{ __('vela::public.topics') }}</h4>
                    <div class="ed-chips" style="flex-wrap:wrap;">
                        @foreach($post->categories as $cat)
                            <a href="{{ route('vela.public.categories.show', Str::slug($cat->name)) }}" class="ed-chip">{{ $cat->translated_name }}</a>
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
<section class="ed-related">
    <div class="ed-container">
        <div class="ed-related-header">
            <h2>{{ __('vela::public.related_articles') }}</h2>
        </div>
        <div class="ed-grid ed-grid-3">
            @foreach($relatedPosts as $relatedPost)
            <a href="{{ route('vela.public.posts.show', $relatedPost->slug) }}" class="ed-card" style="text-decoration:none;color:inherit;">
                <div class="ed-card-img-wrap">
                    @if($relatedPost->main_image)
                        {!! vela_image($relatedPost->main_image->url, $relatedPost->translated_title, [300, 600], 'fit', ['style' => 'width:100%;height:100%;object-fit:cover;']) !!}
                    @else
                        <div class="ed-placeholder">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        </div>
                    @endif
                </div>
                <div style="padding:16px 0;">
                    @if($relatedPost->categories->count() > 0)
                        <span class="ed-card-kicker">{{ $relatedPost->categories->first()->translated_name }}</span>
                    @endif
                    <h3 class="ed-card-title">{{ $relatedPost->translated_title }}</h3>
                    <div class="ed-card-date">
                        <time>{{ ($relatedPost->published_at ?? $relatedPost->created_at)->format('F j, Y') }}</time>
                    </div>
                </div>
            </a>
            @endforeach
        </div>
    </div>
</section>
@endif
@endsection

@php
if (!function_exists('renderEditorialMarkdown')) {
    function renderEditorialMarkdown($text) {
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/`(.*?)`/', '<code>$1</code>', $text);
        return $text;
    }
}
@endphp
