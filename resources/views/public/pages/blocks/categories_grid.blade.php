@php
    $settings = $block->settings ?? [];
    $pick = function (string $key, array $allowed) use ($settings) {
        return in_array($settings[$key] ?? null, $allowed, true) ? $settings[$key] : $allowed[0];
    };

    $maxCount      = max(1, min(50, (int) ($settings['max_count'] ?? 12) ?: 12));
    $showPostCount = (bool) ($settings['show_post_count'] ?? true);
    $hideEmpty     = (bool) ($settings['hide_empty'] ?? false);
    // all: every category in the order the categories screen keeps.
    // chosen: the ones picked in the block, in the order they were put.
    $source        = $pick('source', ['all', 'chosen']);
    $chosenIds     = array_values(array_unique(array_filter(array_map('intval', (array) ($settings['category_ids'] ?? [])))));
    // image: picture on top, as the block always drew. overlay: the name on
    // the picture. compact: icon beside the name. pills: one wrapping line.
    $style         = $pick('card_style', ['image', 'overlay', 'compact', 'pills']);
    // auto keeps the fixed 160px strip every block saved before had.
    $ratio         = $pick('ratio', ['auto', '16x9', '4x3', '1x1']);

    // One query for the counts instead of one per card, and the pictures
    // loaded with the categories rather than asked for card by card.
    $query = \VelaBuild\Core\Models\Category::query()
        ->with('media')
        ->withCount(['contents as published_posts_count' => fn ($q) => $q->where('status', 'published')]);
    if ($hideEmpty) {
        $query->whereHas('contents', fn ($q) => $q->where('status', 'published'));
    }

    if ($source === 'chosen' && $chosenIds !== []) {
        $position   = array_flip($chosenIds);
        $categories = $query->whereIn('id', $chosenIds)->get()
            ->sortBy(fn ($c) => $position[$c->id] ?? PHP_INT_MAX)->values();
    } else {
        $categories = $query->orderBy('order_by')->orderBy('name')->take($maxCount)->get();
    }

    $columns  = max(1, min(6, (int) ($settings['columns'] ?? 3) ?: 3, max(1, $categories->count())));
    // A card with no picture gets its icon on a tinted panel, but only when
    // another card has a picture — otherwise the row would be all panels, and
    // blocks saved before this looked the same without them.
    $anyImage = $categories->contains(fn ($c) => $c->image);
    $usesIcons = in_array($style, ['compact', 'pills'], true) || ($anyImage && $categories->contains(fn ($c) => !$c->image));

    $classes = 'block-categories-grid block-categories-grid--' . $style . ' block-categories-grid--ratio-' . $ratio;
    $css = $style === 'pills' ? '' : 'grid-template-columns:repeat(' . $columns . ',minmax(0,1fr));';
    if ($style !== 'pills' && $columns > 1) {
        $classes .= ' block-categories-grid--multi';
    }
@endphp
@if($categories->isNotEmpty())
@if($usesIcons)
@once
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
@endonce
@endif
<div class="{{ $classes }}"@if($css !== '') style="{{ $css }}"@endif>
@foreach($categories as $category)
@php
        $catUrl   = url('/categories/' . \Illuminate\Support\Str::slug($category->name));
        $image    = $category->image;
        $icon     = trim((string) $category->icon) ?: 'fas fa-folder';
        $count    = (int) $category->published_posts_count;
        $withPicture = $image && in_array($style, ['image', 'overlay'], true);
        $withPanel   = !$image && $anyImage && in_array($style, ['image', 'overlay'], true);
@endphp
    <a href="{{ $catUrl }}" class="category-card{{ $withPicture ? ' has-image' : '' }}{{ $withPanel ? ' has-icon-panel' : '' }}">
@if($withPicture)
        <span class="category-card-media">
            {!! vela_image($image->url, $category->translated_name, [320, 480, 640, 960], 'crop') !!}
        </span>
@elseif($withPanel)
        <span class="category-card-media category-card-media--icon" aria-hidden="true"><i class="{{ $icon }}"></i></span>
@elseif(in_array($style, ['compact', 'pills'], true))
        <span class="category-card-icon" aria-hidden="true"><i class="{{ $icon }}"></i></span>
@endif
        <span class="category-card-body">
            {{-- h2 for the same reason as the posts grid: this block can sit
                 directly under the page's h1, with no h2 to descend from. --}}
            <h2 class="category-card-title">{{ $category->translated_name }}</h2>
@if($showPostCount)
            {{-- trans_choice, as the category pages count: "1 posts" read wrong. --}}
            <span class="category-card-count">{{ trans_choice('vela::public.articles_count', $count, ['count' => $count]) }}</span>
@endif
        </span>
    </a>
@endforeach
</div>
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-folder-tree',
        'title'   => trans('vela::global.categories_grid_empty_title'),
        'message' => trans('vela::global.categories_grid_empty_message'),
        'ctaText' => trans('vela::global.categories_grid_empty_cta'),
        'ctaUrl'  => route('vela.admin.categories.create'),
    ])
@endif
