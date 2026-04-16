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
<div class="md-page-header">
    <div class="md-container">
        <h1>{{ $category->translated_name }}</h1>
        @if($category->description)
            <p>{{ $category->description }}</p>
        @else
            <p>{{ __('vela::public.discover_our') }} {{ $category->translated_name }} {{ __('vela::public.articles') }} {{ __('vela::public.and_find_comprehensive_guides') }}.</p>
        @endif
    </div>
</div>

<!-- Breadcrumb -->
<div class="md-breadcrumb">
    <div class="md-container">
        <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
        <span>/</span>
        <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
        <span>/</span>
        {{ $category->translated_name }}
    </div>
</div>

<!-- Category Filter Chips -->
@if($categories->count() > 1)
<div class="md-filter-bar">
    <div class="md-container">
        <div class="md-chips">
            <a href="{{ route('vela.public.categories.index') }}" class="md-chip">{{ __('vela::public.all_categories') }}</a>
            @foreach($categories as $cat)
                <a href="{{ route('vela.public.categories.show', Str::slug($cat->name)) }}" class="md-chip {{ $cat->id === $category->id ? 'md-chip-active' : '' }}">
                    {{ $cat->translated_name }}
                </a>
            @endforeach
        </div>
    </div>
</div>
@endif

<!-- Articles Grid -->
<section class="md-section">
    <div class="md-container">
        @if($posts->count() > 0)
            <p class="md-results-count">{{ __('vela::public.showing_articles', ['count' => $posts->count()]) }}</p>

            <div class="md-grid md-grid-3">
                @foreach($posts as $post)
                <a href="{{ route('vela.public.posts.show', $post->slug) }}" class="md-card">
                    <div class="md-card-accent"></div>
                    <div class="md-card-img-wrap">
                        @if($post->main_image)
                            {!! vela_image($post->main_image->url, $post->translated_title, [300, 600, 900], 'fit', ['class' => 'md-card-img']) !!}
                        @else
                            <div class="md-placeholder">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            </div>
                        @endif
                    </div>
                    <div class="md-card-body">
                        <div class="md-card-meta">
                            <span class="md-card-date">{{ ($post->published_at ?? $post->created_at)->format('M d, Y') }}</span>
                            <span class="md-chip md-chip-sm">{{ $category->translated_name }}</span>
                        </div>
                        <h3 class="md-card-title">{{ $post->translated_title }}</h3>
                        @if($post->description)
                            <p class="md-card-desc">{{ Str::limit($post->translated_description, 100) }}</p>
                        @endif
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
            <div style="text-align:center;padding:64px 0;">
                <svg style="width:64px;height:64px;color:#9ca3af;margin:0 auto 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <h3 style="font-size:1.25rem;font-weight:600;color:#1e1b4b;margin-bottom:8px;">{{ __('vela::public.no_articles_found') }}</h3>
                <p style="color:#6b7280;margin-bottom:24px;">{{ __('vela::public.no_articles_in_category', ['category' => $category->translated_name]) }}</p>
                <a href="{{ route('vela.public.categories.index') }}" class="md-btn md-btn-primary">{{ __('vela::public.browse_other_categories') }}</a>
            </div>
        @endif
    </div>
</section>

<!-- Other Categories -->
@if($categories->count() > 1)
<section class="md-section md-section-alt">
    <div class="md-container">
        <div class="md-section-header">
            <div>
                <h2 class="md-section-title">{{ __('vela::public.other_categories') }}</h2>
                <p class="md-section-subtitle">{{ __('vela::public.explore_articles_in_other_categories') }}</p>
            </div>
            <a href="{{ route('vela.public.categories.index') }}" class="md-btn md-btn-outline">{{ __('vela::public.view_all') }}</a>
        </div>

        <div class="md-grid md-grid-4">
            @foreach($categories->where('id', '!=', $category->id)->take(4) as $otherCat)
            <a href="{{ route('vela.public.categories.show', Str::slug($otherCat->name)) }}" class="md-card" style="text-align:center;">
                <div class="md-card-accent"></div>
                <div class="md-card-img-wrap" style="height:120px;">
                    @if($otherCat->image)
                        {!! vela_image($otherCat->image->url, $otherCat->translated_name, [200, 400], 'crop', ['class' => 'md-card-img']) !!}
                    @else
                        <div class="md-placeholder" style="height:120px;">
                            @if($otherCat->icon)
                                <i class="{{ $otherCat->icon }}" style="font-size:2rem;color:#7c3aed;"></i>
                            @else
                                <svg style="width:36px;height:36px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="md-card-body">
                    <h4 class="md-card-title" style="font-size:0.95rem;">{{ $otherCat->translated_name }}</h4>
                    <p class="md-card-meta">
                        <span class="md-card-date">
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
