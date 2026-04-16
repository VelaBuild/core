@php
    $settings = $block->settings ?? [];
    $maxCount = (int)($settings['max_count'] ?? 12);
    $columns = (int)($settings['columns'] ?? 3);
    $categoryId = $settings['category_id'] ?? '';
    $orderBy = $settings['order_by'] ?? 'newest';
    $showExcerpt = $settings['show_excerpt'] ?? true;

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

    $posts = $query->take($maxCount)->get();
@endphp
@if($posts->isNotEmpty())
<div class="block-posts-grid" style="display:grid;grid-template-columns:repeat({{ $columns }},1fr);gap:20px;">
    @foreach($posts as $post)
        <a href="{{ url('/posts/' . $post->slug) }}" class="post-card" style="display:block;text-decoration:none;color:inherit;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;transition:box-shadow .2s;">
            @if($post->main_image)
                <img src="{{ $post->main_image->url }}" alt="{{ e($post->translated_title) }}" style="width:100%;height:180px;object-fit:cover;" loading="lazy">
            @endif
            <div style="padding:16px;">
                <h3 style="margin:0 0 8px;font-size:1.05em;">{{ e($post->translated_title) }}</h3>
                @if($showExcerpt && $post->translated_description)
                    <p style="margin:0 0 8px;font-size:0.9em;color:#4b5563;">{{ \Illuminate\Support\Str::limit(strip_tags($post->translated_description), 120) }}</p>
                @endif
                <small style="color:#9ca3af;">{{ ($post->published_at ?? $post->created_at)->format('M j, Y') }}</small>
            </div>
        </a>
    @endforeach
</div>
@endif
