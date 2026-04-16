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
<div class="co-page-header">
    <div class="co-container">
        <h1>{{ __('vela::public.all_articles') }}</h1>
        <p>{{ __('vela::public.all_articles_description') }}</p>
    </div>
</div>

<!-- Breadcrumb -->
<div class="co-breadcrumb">
    <div class="co-container">
        <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
        <span>/</span>
        {{ __('vela::public.all_articles') }}
    </div>
</div>

<!-- Category Filter -->
@if($categories->count() > 0)
<div class="co-filter-bar">
    <div class="co-container">
        <div class="co-chips">
            <a href="{{ route('vela.public.posts.index') }}" class="co-chip {{ !request('category') ? 'co-chip-active' : '' }}">{{ __('vela::public.all') }}</a>
            @foreach($categories as $category)
                <a href="{{ route('vela.public.categories.show', Str::slug($category->name)) }}" class="co-chip">
                    {{ $category->translated_name }}
                    <span class="co-chip-count">{{ $category->contents()->where('status', 'published')->count() }}</span>
                </a>
            @endforeach
        </div>
    </div>
</div>
@endif

<!-- Articles Grid -->
<section class="co-section">
    <div class="co-container">
        @if($posts->count() > 0)
            <p class="co-results-count">{{ __('vela::public.showing_articles', ['count' => $posts->count()]) }}</p>

            <div class="co-grid co-grid-3">
                @foreach($posts as $post)
                <a href="{{ route('vela.public.posts.show', $post->slug) }}" class="co-card">
                    <div class="co-card-img-wrap">
                        @if($post->main_image)
                            {!! vela_image($post->main_image->url, $post->translated_title, [300, 600, 900], 'fit', ['class' => 'co-card-img']) !!}
                        @else
                            <div class="co-placeholder">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            </div>
                        @endif
                    </div>
                    <div class="co-card-body">
                        <div class="co-card-meta">
                            <span class="co-card-date">{{ ($post->published_at ?? $post->created_at)->format('M d, Y') }}</span>
                            @if($post->categories->count() > 0)
                                <span class="co-card-category">{{ $post->categories->first()->translated_name }}</span>
                            @endif
                        </div>
                        <h3 class="co-card-title">{{ $post->translated_title }}</h3>
                        @if($post->description)
                            <p class="co-card-desc">{{ Str::limit($post->translated_description, 100) }}</p>
                        @endif
                    </div>
                </a>
                @endforeach
            </div>

            @if($posts->hasPages())
            <div class="co-pagination">
                {{ $posts->links() }}
            </div>
            @endif
        @else
            <div style="text-align:center;padding:64px 0;">
                <p style="font-size:1.1rem;color:#6b7280;margin-bottom:16px;">{{ __('vela::public.no_articles_found') }}</p>
                <a href="{{ route('vela.public.home') }}" class="co-btn co-btn-primary">{{ __('vela::public.back_to_home') }}</a>
            </div>
        @endif
    </div>
</section>
@endsection
