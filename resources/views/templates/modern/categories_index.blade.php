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
        <h1>{{ __('vela::public.article_categories') }}</h1>
        <p>{{ __('vela::public.explore_article_categories_description') }}</p>
    </div>
</div>

<!-- Breadcrumb -->
<div class="md-breadcrumb">
    <div class="md-container">
        <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
        <span>/</span>
        {{ __('vela::public.topics') }}
    </div>
</div>

<!-- Categories Grid -->
<section class="md-section">
    <div class="md-container">
        @if($categories->count() > 0)
            <div class="md-grid md-grid-3">
                @foreach($categories as $category)
                <a href="{{ route('vela.public.categories.show', Str::slug($category->name)) }}" class="md-card">
                    <div class="md-card-accent"></div>
                    <div class="md-card-img-wrap">
                        @if($category->image)
                            {!! vela_image($category->image->url, $category->translated_name, [300, 600], 'crop', ['class' => 'md-card-img']) !!}
                        @else
                            <div class="md-placeholder">
                                @if($category->icon)
                                    <i class="{{ $category->icon }}" style="font-size:2.5rem;color:#7c3aed;"></i>
                                @else
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                                @endif
                            </div>
                        @endif
                    </div>
                    <div class="md-card-body">
                        <h3 class="md-card-title">{{ $category->translated_name }}</h3>
                        @if($category->description)
                            <p class="md-card-desc">{{ Str::limit($category->description, 100) }}</p>
                        @endif
                        <p class="md-card-meta">
                            <span class="md-card-date">
                                {{ trans_choice('vela::public.articles_count', $category->contents()->where('status', 'published')->count(), ['count' => $category->contents()->where('status', 'published')->count()]) }}
                            </span>
                        </p>
                    </div>
                </a>
                @endforeach
            </div>
        @else
            <div style="text-align:center;padding:64px 0;">
                <svg style="width:64px;height:64px;color:#9ca3af;margin:0 auto 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                </svg>
                <h3 style="font-size:1.25rem;font-weight:600;color:#1e1b4b;margin-bottom:8px;">{{ __('vela::public.no_categories_available') }}</h3>
                <p style="color:#6b7280;margin-bottom:24px;">{{ __('vela::public.check_back_later_categories') }}</p>
                <a href="{{ route('vela.public.posts.index') }}" class="md-btn md-btn-primary">{{ __('vela::public.view_all_articles') }}</a>
            </div>
        @endif
    </div>
</section>

<!-- CTA -->
<section class="md-section md-section-alt">
    <div class="md-container" style="text-align:center;">
        <h2 class="md-section-title">{{ __('vela::public.cant_find_what_youre_looking_for') }}</h2>
        <p class="md-section-subtitle">{{ __('vela::public.browse_all_articles_or_contact_us') }}</p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:24px;">
            <a href="{{ route('vela.public.posts.index') }}" class="md-btn md-btn-primary">{{ __('vela::public.view_all_articles') }}</a>
            <a href="{{ route('vela.public.home') }}" class="md-btn md-btn-outline">{{ __('vela::public.back_to_home') }}</a>
        </div>
    </div>
</section>
@endsection
