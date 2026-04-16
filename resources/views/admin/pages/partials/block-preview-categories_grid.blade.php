@php
    $settings = $block->settings ?? [];
    $maxCount = (int)($settings['max_count'] ?? 12);
    $columns = (int)($settings['columns'] ?? 3);
    $showPostCount = $settings['show_post_count'] ?? true;
@endphp
<div style="background:#f8f9fa; border-radius:4px; padding:12px;">
    <small class="text-muted">
        <i class="fas fa-th-large mr-1"></i>
        {{ trans('vela::global.categories_grid') }}
        &mdash; {{ $columns }} {{ trans('vela::global.columns') }}, max {{ $maxCount }}
        @if($showPostCount) ({{ trans('vela::global.show_post_count_label') }}) @endif
    </small>
</div>
