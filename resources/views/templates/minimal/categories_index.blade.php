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
        <h1>{{ __('vela::public.article_categories') }}</h1>
        <p>{{ __('vela::public.explore_article_categories_description') }}</p>
    </div>
</div>

<section class="mn-section">
    <div class="mn-container">
        @if($categories->count() > 0)
        <div class="mn-grid">
            @foreach($categories as $category)
            <a href="{{ route('vela.public.categories.show', Str::slug($category->name)) }}" class="mn-card">
                <div class="mn-card-img">
                    @if($category->image)
                        {!! vela_image($category->image->url, $category->name, [200, 400, 800], 'crop', []) !!}
                    @else
                        <div class="mn-placeholder">
                            @if($category->icon)
                                <i class="{{ $category->icon }}" style="font-size:2rem;"></i>
                            @else
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="mn-card-body" style="text-align:center;">
                    <h3 class="mn-card-title" style="justify-content:center;">{{ $category->translated_name }}</h3>
                    <p class="mn-card-desc">
                        {{ trans_choice('vela::public.articles_count', $category->contents()->where('status', 'published')->count(), ['count' => $category->contents()->where('status', 'published')->count()]) }}
                    </p>
                </div>
            </a>
            @endforeach
        </div>
        @else
        <div style="text-align:center;padding:64px 0;">
            <p style="font-size:1.1rem;color:#666;">{{ __('vela::public.no_categories_available') }}</p>
        </div>
        @endif
    </div>
</section>

<section class="mn-section mn-section-alt" style="text-align:center;">
    <div class="mn-container">
        <h2 style="margin-bottom:12px;">{{ __('vela::public.cant_find_what_youre_looking_for') }}</h2>
        <p style="color:#666;max-width:480px;margin:0 auto 24px;">{{ __('vela::public.browse_all_articles_or_contact_us') }}</p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
            <a href="{{ route('vela.public.posts.index') }}" class="mn-btn mn-btn-primary">{{ __('vela::public.view_all_articles') }}</a>
            <a href="{{ route('vela.public.home') }}" class="mn-btn mn-btn-outline">{{ __('vela::public.back_to_home') }}</a>
        </div>
    </div>
</section>
@endsection
