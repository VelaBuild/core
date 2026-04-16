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
<div class="ed-posts-header">
    <div class="ed-container">
        <h1>{{ __('vela::public.all_articles') }}</h1>
        <p>{{ __('vela::public.all_articles_description') }}</p>
    </div>
</div>

<!-- Breadcrumb -->
<div class="ed-breadcrumb">
    <div class="ed-container">
        <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
        <span class="ed-breadcrumb-sep">/</span>
        {{ __('vela::public.all_articles') }}
    </div>
</div>

<!-- Category Filter -->
@if($categories->count() > 0)
<div class="ed-section-sm ed-section-bg">
    <div class="ed-container">
        <div class="ed-chips">
            <a href="{{ route('vela.public.posts.index') }}" class="ed-chip {{ !request('category') ? 'ed-chip-active' : '' }}">{{ __('vela::public.all') }}</a>
            @foreach($categories as $category)
                <a href="{{ route('vela.public.categories.show', Str::slug($category->name)) }}" class="ed-chip">
                    {{ $category->translated_name }}
                    <span style="font-size:0.75rem;color:#9ca3af;">({{ $category->contents()->where('status', 'published')->count() }})</span>
                </a>
            @endforeach
        </div>
    </div>
</div>
@endif

<!-- Articles -->
<section class="ed-section">
    <div class="ed-container">
        @if($posts->count() > 0)
            <div class="ed-grid ed-grid-2">
                @foreach($posts as $post)
                <a href="{{ route('vela.public.posts.show', $post->slug) }}" class="ed-card" style="text-decoration:none;color:inherit;">
                    <div class="ed-card-img-wrap">
                        @if($post->main_image)
                            {!! vela_image($post->main_image->url, $post->translated_title, [400, 800], 'fit', ['style' => 'width:100%;height:100%;object-fit:cover;']) !!}
                        @else
                            <div class="ed-placeholder">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            </div>
                        @endif
                    </div>
                    <div style="padding:20px 0;">
                        @if($post->categories->count() > 0)
                            <span class="ed-card-kicker">{{ $post->categories->first()->translated_name }}</span>
                        @endif
                        <h2 class="ed-card-title">{{ $post->translated_title }}</h2>
                        @if($post->translated_description)
                            <p class="ed-card-excerpt">{{ Str::limit($post->translated_description, 140) }}</p>
                        @endif
                        <div class="ed-card-date">
                            <time>{{ ($post->published_at ?? $post->created_at)->format('F j, Y') }}</time>
                        </div>
                    </div>
                </a>
                @endforeach
            </div>

            @if($posts->hasPages())
            <div class="ed-pagination">
                {{ $posts->links() }}
            </div>
            @endif
        @else
            <div class="ed-empty-state">
                <i class="fas fa-newspaper" style="font-size:2.5rem; color:#d1d5db; margin-bottom:20px;"></i>
                <h3>{{ __('vela::public.no_articles_found') }}</h3>
                <p>{{ __('vela::public.no_articles_description') }}</p>
                <a href="{{ route('vela.public.home') }}" class="ed-btn ed-btn-primary">{{ __('vela::public.back_to_home') }}</a>
            </div>
        @endif
    </div>
</section>
@endsection
