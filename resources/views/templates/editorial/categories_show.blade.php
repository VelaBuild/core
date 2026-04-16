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
<!-- Category Header -->
<div class="ed-posts-header">
    <div class="ed-container">
        <span class="ed-masthead-label">{{ __('vela::public.topics') }}</span>
        <h1>{{ $category->translated_name }}</h1>
        @if($category->description)
            <p>{{ $category->description }}</p>
        @else
            <p>{{ __('vela::public.discover_our') }} {{ $category->translated_name }} {{ __('vela::public.articles') }} {{ __('vela::public.and_find_comprehensive_guides') }}.</p>
        @endif
    </div>
</div>

<!-- Breadcrumb -->
<div class="ed-breadcrumb">
    <div class="ed-container">
        <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
        <span class="ed-breadcrumb-sep">/</span>
        <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
        <span class="ed-breadcrumb-sep">/</span>
        {{ $category->translated_name }}
    </div>
</div>

<!-- Category Filter Chips -->
@if($categories->count() > 1)
<div class="ed-section-sm ed-section-bg">
    <div class="ed-container">
        <div class="ed-chips">
            <a href="{{ route('vela.public.categories.index') }}" class="ed-chip">{{ __('vela::public.all_categories') }}</a>
            @foreach($categories as $cat)
                <a href="{{ route('vela.public.categories.show', Str::slug($cat->name)) }}" class="ed-chip {{ $cat->id === $category->id ? 'ed-chip-active' : '' }}">
                    {{ $cat->translated_name }}
                </a>
            @endforeach
        </div>
    </div>
</div>
@endif

<!-- Articles Grid -->
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
                        <span class="ed-card-kicker">{{ $category->translated_name }}</span>
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
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                <h3>{{ __('vela::public.no_articles_found') }}</h3>
                <p>{{ __('vela::public.no_articles_in_category', ['category' => $category->translated_name]) }}</p>
                <a href="{{ route('vela.public.categories.index') }}" class="ed-btn ed-btn-primary">{{ __('vela::public.browse_other_categories') }}</a>
            </div>
        @endif
    </div>
</section>

<!-- Other Categories -->
@if($categories->count() > 1)
<section class="ed-section ed-section-bg">
    <div class="ed-container">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:32px;">
            <div>
                <h2 class="ed-section-title">{{ __('vela::public.other_categories') }}</h2>
                <p style="color:#6b7280;margin:0;">{{ __('vela::public.explore_articles_in_other_categories') }}</p>
            </div>
            <a href="{{ route('vela.public.categories.index') }}" class="ed-btn ed-btn-outline">{{ __('vela::public.view_all') }}</a>
        </div>

        <div class="ed-chips">
            @foreach($categories->where('id', '!=', $category->id)->take(8) as $otherCat)
            <a href="{{ route('vela.public.categories.show', Str::slug($otherCat->name)) }}" class="ed-chip">
                {{ $otherCat->translated_name }}
                <span style="font-size:0.75rem;color:#9ca3af;margin-left:4px;">({{ $otherCat->contents()->where('status', 'published')->count() }})</span>
            </a>
            @endforeach
        </div>
    </div>
</section>
@endif
@endsection
