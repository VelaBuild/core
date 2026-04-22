@php
    $settings = $block->settings ?? [];
    $maxCount = (int)($settings['max_count'] ?? 12);
    $columns = (int)($settings['columns'] ?? 3);
    $showPostCount = $settings['show_post_count'] ?? true;

    $categories = \VelaBuild\Core\Models\Category::orderBy('order_by')
        ->orderBy('name')
        ->take($maxCount)
        ->get();
@endphp
@if($categories->isNotEmpty())
<div class="block-categories-grid" style="display:grid;grid-template-columns:repeat({{ $columns }},1fr);gap:20px;">
@foreach($categories as $category)
@php
        $catSlug = \Illuminate\Support\Str::slug($category->name);
        $catUrl  = url('/categories/' . $catSlug);
@endphp
    <a href="{{ $catUrl }}" class="category-card" style="display:block;text-decoration:none;color:inherit;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;transition:box-shadow .2s;">
@if($category->image)
        {!! vela_image($category->image->url, $category->translated_name, [320, 480, 640, 960], 'crop', ['style' => 'width:100%;height:160px;object-fit:cover;']) !!}
@endif
        <div style="padding:16px;">
            <h3 style="margin:0 0 4px;font-size:1.1em;">{{ $category->translated_name }}</h3>
@if($showPostCount)
            <span style="font-size:0.85em;color:#6b7280;">{{ $category->contents()->where('status', 'published')->count() }} {{ trans('vela::global.posts') }}</span>
@endif
        </div>
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
