@php
    $content = $block->content;
    $settings = $block->settings ?? [];
    $url = $content['url'] ?? '';
    $alt = $content['alt'] ?? '';
    $caption = $content['caption'] ?? '';
    $link = $settings['link'] ?? '';
    $maxWidth = $settings['max_width'] ?? '100%';
@endphp
@if($url)
    <div style="max-width: {{ e($maxWidth) }};">
        <img src="{{ e($url) }}" alt="{{ e($alt) }}" style="max-height:200px;">
        @if($caption)<br><small class="text-muted">{{ e($caption) }}</small>@endif
        @if($alt)<br><small class="text-muted">Alt: {{ e($alt) }}</small>@endif
        @if($link)<br><small class="text-muted">Link: {{ e($link) }}</small>@endif
    </div>
@else
    <em class="text-muted">{{ trans('vela::global.no_image_set') }}</em>
@endif
