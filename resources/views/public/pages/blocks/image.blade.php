@php
    $content = $block->content;
    $settings = $block->settings ?? [];
    $url = $content['url'] ?? '';
    $alt = $content['alt'] ?? '';
    $caption = $content['caption'] ?? '';
    $link = $settings['link'] ?? '';
    $maxWidth = $settings['max_width'] ?? '100%';
@endphp
<div class="block-image" style="max-width: {{ e($maxWidth) }};">
    @if($link)
        <a href="{{ e($link) }}" target="_blank" rel="noopener">
    @endif
    <figure>
        <img src="{{ e($url) }}" alt="{{ e($alt) }}" loading="lazy">
        @if($caption)
            <figcaption>{{ e($caption) }}</figcaption>
        @endif
    </figure>
    @if($link)
        </a>
    @endif
</div>
