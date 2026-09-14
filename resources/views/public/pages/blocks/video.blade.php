@php
    $content = $block->content ?? [];
    $settings = $block->settings ?? [];
    // One place turns a link and its settings into a player address; the page
    // editor's preview is held to the same cases. See VideoEmbed.
    $embedUrl = \VelaBuild\Core\Services\VideoEmbed::url($content['url'] ?? '', $settings);
    $padding = \VelaBuild\Core\Services\VideoEmbed::padding($settings);
    $maxWidth = \VelaBuild\Core\Services\VideoEmbed::maxWidth($settings);
    // Read aloud in place of the frame. Without one a screen reader announces
    // only "frame", which says nothing about what is in it.
    $title = trim((string) ($content['title'] ?? '')) ?: __('vela::global.block_type_video');
@endphp
@if($embedUrl)
{{-- The shape is inline rather than a class so it is right on a site that has
     not rebuilt its asset bundle since these settings arrived. --}}
<div class="block-video-holder" @if($maxWidth) style="max-width:{{ $maxWidth }};margin-left:auto;margin-right:auto;" @endif>
<div class="block-video" style="padding-bottom:{{ $padding }};">
    <iframe src="{{ $embedUrl }}"
            title="{{ $title }}"
            {{-- autoplay has to be allowed by the page as well as asked for by
                 the address, or the browser refuses it anyway. --}}
            allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
            {{-- YouTube refuses to play an embed that sends no referrer at all
                 (error 153), which a strict site-wide policy can cause. --}}
            referrerpolicy="strict-origin-when-cross-origin"
            allowfullscreen loading="lazy"></iframe>
</div>
</div>
@endif
