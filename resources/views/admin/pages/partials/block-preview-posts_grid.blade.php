@php
    $settings = $block->settings ?? [];
    $maxCount = (int)($settings['max_count'] ?? 12);
    $columns = (int)($settings['columns'] ?? 3);
    $orderBy = $settings['order_by'] ?? 'newest';
    $categoryId = $settings['category_id'] ?? '';
    $showExcerpt = $settings['show_excerpt'] ?? true;
@endphp
<div style="background:#f8f9fa; border-radius:4px; padding:12px;">
    <small class="text-muted">
        <i class="fas fa-newspaper mr-1"></i>
        {{ trans('vela::global.posts_grid') }}
        &mdash; {{ $columns }} {{ trans('vela::global.columns') }}, max {{ $maxCount }}, {{ $orderBy }}
        @if($categoryId) (category #{{ $categoryId }}) @endif
    </small>
</div>
