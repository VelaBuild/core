@php
    $content = $block->content;
    $settings = $block->settings ?? [];
    $url = $content['url'] ?? '';
    $alt = $content['alt'] ?? '';
    $caption = $content['caption'] ?? '';
    $link = $settings['link'] ?? '';
    $maxWidth = $settings['max_width'] ?? '100%';
    $imgSizes = $settings['sizes'] ?? [640, 960, 1280, 1920];
    $mode = $settings['mode'] ?? 'fit';
@endphp
<div class="block-image" style="max-width: {{ $maxWidth }};">
@if($link)
    <a href="{{ $link }}" target="_blank" rel="noopener">
@endif
    <figure>
        {!! $url ? vela_image($url, $alt, $imgSizes, $mode, ['class' => 'block-image-img']) : '' !!}
@if($caption)
        <figcaption>{{ $caption }}</figcaption>
@endif
    </figure>
@if($link)
    </a>
@endif
</div>
