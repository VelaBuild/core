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
<div class="dk-page-header">
    <div class="dk-container">
        <h1>{{ $category->translated_name }}</h1>
        @if($category->description)
            <p>{{ $category->description }}</p>
        @else
            <p>{{ __('vela::public.discover_our') }} {{ $category->translated_name }} {{ __('vela::public.articles') }} {{ __('vela::public.and_find_comprehensive_guides') }}.</p>
        @endif
    </div>
</div>

<!-- Breadcrumb -->
<div class="dk-breadcrumb">
    <div class="dk-container">
        <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
        <span>/</span>
        <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
        <span>/</span>
        {{ $category->translated_name }}
    </div>
</div>

<!-- Category Filter Chips -->
@if($categories->count() > 1)
<div class="dk-filter-bar">
    <div class="dk-container">
        <div class="dk-chips">
            <a href="{{ route('vela.public.categories.index') }}" class="dk-chip">{{ __('vela::public.all_categories') }}</a>
            @foreach($categories as $cat)
                <a href="{{ route('vela.public.categories.show', Str::slug($cat->name)) }}" class="dk-chip {{ $cat->id === $category->id ? 'dk-chip-active' : '' }}">
                    {{ $cat->translated_name }}
                </a>
            @endforeach
        </div>
    </div>
</div>
@endif

<!-- Articles Grid -->
<section class="dk-section">
    <div class="dk-container">
        @if($posts->count() > 0)
            <p class="dk-results-count">{{ __('vela::public.showing_articles', ['count' => $posts->count()]) }}</p>

            <div class="dk-grid dk-grid-3">
                @foreach($posts as $post)
                <a href="{{ route('vela.public.posts.show', $post->slug) }}" class="dk-card">
                    <div class="dk-card-img-wrap">
                        @if($post->main_image)
                            {!! vela_image($post->main_image->url, $post->translated_title, [300, 600, 900], 'fit', ['class' => 'dk-card-img']) !!}
                        @else
                            <div class="dk-placeholder">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            </div>
                        @endif
                    </div>
                    <div class="dk-card-body">
                        <div class="dk-card-meta">
                            <span class="dk-card-date">{{ ($post->published_at ?? $post->created_at)->format('M d, Y') }}</span>
                            <span class="dk-card-category">{{ $category->translated_name }}</span>
                        </div>
                        <h3 class="dk-card-title">{{ $post->translated_title }}</h3>
                        @if($post->description)
                            <p class="dk-card-desc">{{ Str::limit($post->translated_description, 100) }}</p>
                        @endif
                    </div>
                </a>
                @endforeach
            </div>

            @if($posts->hasPages())
            <div class="dk-pagination">
                {{ $posts->links() }}
            </div>
            @endif
        @else
            <div class="dk-empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                <h3>{{ __('vela::public.no_articles_found') }}</h3>
                <p>{{ __('vela::public.no_articles_in_category', ['category' => $category->translated_name]) }}</p>
                <a href="{{ route('vela.public.categories.index') }}" class="dk-btn dk-btn-primary">{{ __('vela::public.browse_other_categories') }}</a>
            </div>
        @endif
    </div>
</section>

<!-- Other Categories -->
@if($categories->count() > 1)
<section class="dk-section dk-section-alt">
    <div class="dk-container">
        <div class="dk-section-header">
            <div>
                <h2 class="dk-section-title">{{ __('vela::public.other_categories') }}</h2>
                <p class="dk-section-subtitle">{{ __('vela::public.explore_articles_in_other_categories') }}</p>
            </div>
            <a href="{{ route('vela.public.categories.index') }}" class="dk-btn dk-btn-outline">{{ __('vela::public.view_all') }}</a>
        </div>

        <div class="dk-grid dk-grid-4">
            @foreach($categories->where('id', '!=', $category->id)->take(4) as $otherCat)
            <a href="{{ route('vela.public.categories.show', Str::slug($otherCat->name)) }}" class="dk-card" style="text-align:center;">
                <div class="dk-card-img-wrap" style="height:120px;">
                    @if($otherCat->image)
                        {!! vela_image($otherCat->image->url, $otherCat->translated_name, [200, 400], 'crop', ['class' => 'dk-card-img']) !!}
                    @else
                        <div class="dk-placeholder" style="height:120px;">
                            @if($otherCat->icon)
                                <i class="{{ $otherCat->icon }}" style="font-size:2rem;color:#14b8a6;"></i>
                            @else
                                <svg style="width:36px;height:36px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="dk-card-body">
                    <h4 class="dk-card-title" style="font-size:0.95rem;">{{ $otherCat->translated_name }}</h4>
                    <p class="dk-card-meta">
                        <span class="dk-card-date">
                            {{ trans_choice('vela::public.articles_count', $otherCat->contents()->where('status', 'published')->count(), ['count' => $otherCat->contents()->where('status', 'published')->count()]) }}
                        </span>
                    </p>
                </div>
            </a>
            @endforeach
        </div>
    </div>
</section>
@endif
@endsection
