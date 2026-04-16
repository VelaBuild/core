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
<div class="mn-page-header">
    <div class="mn-container">
        <h1>{{ $category->translated_name }}</h1>
        <p>{{ __('vela::public.discover_our') }} {{ $category->translated_name }} {{ __('vela::public.articles') }}</p>
    </div>
</div>

<!-- Breadcrumb -->
<div class="mn-breadcrumb">
    <div class="mn-container">
        <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
        <span>/</span>
        <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
        <span>/</span>
        {{ $category->translated_name }}
    </div>
</div>

<section class="mn-section">
    <div class="mn-container">
        @if($posts->count() > 0)
            <p style="color:#888;margin-bottom:24px;">{{ __('vela::public.showing_category_articles', ['count' => $posts->count(), 'category' => $category->translated_name]) }}</p>

            <div class="mn-grid">
                @foreach($posts as $post)
                <a href="{{ route('vela.public.posts.show', $post->slug) }}" class="mn-card">
                    <div class="mn-card-img">
                        @if($post->main_image)
                            {!! vela_image($post->main_image->url, $post->translated_title, [300, 600, 900], 'fit', []) !!}
                        @else
                            <div class="mn-placeholder">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            </div>
                        @endif
                    </div>
                    <div class="mn-card-body">
                        <div class="mn-card-meta">
                            <span>{{ ($post->published_at ?? $post->created_at)->format('M d, Y') }}</span>
                            <span>{{ $category->translated_name }}</span>
                        </div>
                        <h3 class="mn-card-title">{{ $post->translated_title }}</h3>
                        @if($post->description)
                            <p class="mn-card-desc">{{ Str::limit($post->translated_description, 100) }}</p>
                        @endif
                    </div>
                </a>
                @endforeach
            </div>

            @if($posts->hasPages())
            <div class="mn-pagination">
                {{ $posts->links() }}
            </div>
            @endif
        @else
            <div style="text-align:center;padding:64px 0;">
                <p style="font-size:1.1rem;color:#666;margin-bottom:16px;">{{ __('vela::public.no_articles_found') }}</p>
                <a href="{{ route('vela.public.categories.index') }}" class="mn-btn mn-btn-primary">{{ __('vela::public.browse_other_categories') }}</a>
            </div>
        @endif
    </div>
</section>

<!-- Other Categories -->
@if($categories->count() > 1)
<section class="mn-section mn-section-alt">
    <div class="mn-container">
        <div class="mn-section-header" style="text-align:center;">
            <h2>{{ __('vela::public.other_categories') }}</h2>
            <p>{{ __('vela::public.explore_articles_in_other_categories') }}</p>
        </div>
        <div class="mn-chips" style="justify-content:center;">
            @foreach($categories->where('id', '!=', $category->id)->take(6) as $otherCategory)
                <a href="{{ route('vela.public.categories.show', Str::slug($otherCategory->name)) }}" class="mn-chip">
                    @if($otherCategory->image)
                        {!! vela_image($otherCategory->image->url, $otherCategory->name, [200, 400, 800], 'crop', []) !!}
                    @endif
                    <span>{{ $otherCategory->translated_name }}</span>
                    <span class="mn-chip-count">{{ $otherCategory->contents()->where('status', 'published')->count() }}</span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif
@endsection
