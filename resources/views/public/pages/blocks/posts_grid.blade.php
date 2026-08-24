@php
    $settings = $block->settings ?? [];
    $maxCount = (int)($settings['max_count'] ?? 12);
    $columns = (int)($settings['columns'] ?? 3);
    $categoryId = $settings['category_id'] ?? '';
    $orderBy = $settings['order_by'] ?? 'newest';
    $showExcerpt = $settings['show_excerpt'] ?? true;
    // A page that leads with a featured post and follows it with a grid needs
    // the grid to start after it, or the same article appears twice.
    $skip = max(0, (int)($settings['skip'] ?? 0));

    $query = \VelaBuild\Core\Models\Content::where('status', 'published');

    if ($categoryId) {
        $query->whereHas('categories', function ($q) use ($categoryId) {
            $q->where('vela_categories.id', (int)$categoryId);
        });
    }

    switch ($orderBy) {
        case 'oldest':
            $query->orderByRaw('COALESCE(published_at, created_at) ASC');
            break;
        case 'title_asc':
            $query->orderBy('title', 'asc');
            break;
        case 'title_desc':
            $query->orderBy('title', 'desc');
            break;
        default:
            $query->orderByRaw('COALESCE(published_at, created_at) DESC');
    }

    $posts = $query->skip($skip)->take($maxCount)->get();
@endphp
@if($posts->isNotEmpty())
<div class="block-posts-grid" style="display:grid;grid-template-columns:repeat({{ $columns }},1fr);gap:20px;">
@foreach($posts as $i => $post)
    <a href="{{ url('/posts/' . $post->slug) }}" class="post-card" style="display:block;text-decoration:none;color:inherit;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;transition:box-shadow .2s;">
@if($post->main_image)
        {{-- The first card is routinely the page's largest paint. Lazy-loading
             it delays that paint by a whole round trip, so the lead image is
             fetched eagerly and the rest stay lazy. --}}
        {!! vela_image($post->main_image->url, $post->translated_title, [320, 480, 640, 960], 'crop', ['style' => 'width:100%;height:180px;object-fit:cover;'], $loop->first && $skip === 0 ? 'preload' : 'lazy') !!}
@endif
        <div style="padding:16px;">
            {{-- h2, not h3: a grid placed straight under the page's h1 has no
                 h2 above it to descend from, and h2 stays valid when it does. --}}
            <h2 style="margin:0 0 8px;font-size:1.05em;">{{ $post->translated_title }}</h2>
@if($showExcerpt && $post->translated_description)
            {{-- currentColor, not a fixed grey: this block is dropped into rows
                 of any colour, and the #4b5563 it used to be measured 2.61:1
                 against a dark theme's near-black row. --}}
            <p style="margin:0 0 8px;font-size:0.9em;opacity:0.85;">{{ \Illuminate\Support\Str::limit(strip_tags($post->translated_description), 120) }}</p>
@endif
            <small style="opacity:0.9;">{{ ($post->published_at ?? $post->created_at)->format('M j, Y') }}</small>
        </div>
    </a>
@endforeach
</div>
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-newspaper',
        'title'   => trans('vela::global.posts_grid_empty_title'),
        'message' => trans('vela::global.posts_grid_empty_message'),
        'ctaText' => trans('vela::global.posts_grid_empty_cta'),
        'ctaUrl'  => route('vela.admin.contents.create'),
    ])
@endif
