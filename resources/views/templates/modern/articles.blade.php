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
<div class="md-posts-header">
    <div class="md-container">
        <h1>{{ __('vela::public.all_articles') }}</h1>
        <p>{{ __('vela::public.all_articles_description') }}</p>
    </div>
</div>

<!-- Breadcrumb -->
<div class="md-breadcrumb">
    <div class="md-container">
        <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
        <span class="md-breadcrumb-sep">/</span>
        {{ __('vela::public.all_articles') }}
    </div>
</div>

<!-- Category Filter -->
@if($categories->count() > 0)
<div class="md-section-sm md-section-bg">
    <div class="md-container">
        <div class="md-chips">
            <a href="{{ route('vela.public.posts.index') }}" class="md-chip {{ !request('category') ? 'md-chip-active' : '' }}">{{ __('vela::public.all') }}</a>
            @foreach($categories as $category)
                <a href="{{ route('vela.public.categories.show', Str::slug($category->name)) }}" class="md-chip">
                    {{ $category->translated_name }}
                    <span style="font-size:0.75rem;opacity:0.7;">({{ $category->contents()->where('status', 'published')->count() }})</span>
                </a>
            @endforeach
        </div>
    </div>
</div>
@endif

<!-- Articles -->
<section class="md-section">
    <div class="md-container">
        @if($posts->count() > 0)
            <div class="md-grid md-grid-3">
                @foreach($posts as $post)
                <a href="{{ route('vela.public.posts.show', $post->slug) }}" class="md-card" style="text-decoration:none;color:inherit;">
                    <div class="md-card-img-wrap">
                        @if($post->main_image)
                            {!! vela_image($post->main_image->url, $post->translated_title, [400, 800], 'fit', ['style' => 'width:100%;height:100%;object-fit:cover;']) !!}
                        @else
                            <div class="md-placeholder">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            </div>
                        @endif
                    </div>
                    <div class="md-card-accent"></div>
                    <div class="md-card-body">
                        @if($post->categories->count() > 0)
                            <span class="md-card-category">{{ $post->categories->first()->translated_name }}</span>
                        @endif
                        <h2 class="md-card-title">{{ $post->translated_title }}</h2>
                        @if($post->translated_description)
                            <p class="md-card-excerpt">{{ Str::limit($post->translated_description, 110) }}</p>
                        @endif
                        <div class="md-card-meta">
                            <time>{{ ($post->published_at ?? $post->created_at)->format('M j, Y') }}</time>
                        </div>
                    </div>
                </a>
                @endforeach
            </div>

            @if($posts->hasPages())
            <div class="md-pagination">
                {{ $posts->links() }}
            </div>
            @endif
        @else
            <div class="md-empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                <h3>{{ __('vela::public.no_articles_found') }}</h3>
                <p>{{ __('vela::public.no_articles_description') }}</p>
                <a href="{{ route('vela.public.home') }}" class="md-btn md-btn-primary">{{ __('vela::public.back_to_home') }}</a>
            </div>
        @endif
    </div>
</section>
@endsection
