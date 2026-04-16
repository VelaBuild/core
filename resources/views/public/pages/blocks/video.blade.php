@php
    $content = $block->content;
    $url = $content['url'] ?? '';
    $embedUrl = '';
    // YouTube
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $matches)) {
        $embedUrl = 'https://www.youtube.com/embed/' . $matches[1];
    }
    // Vimeo
    elseif (preg_match('/vimeo\.com\/(?:video\/)?(\d+)/', $url, $matches)) {
        $embedUrl = 'https://player.vimeo.com/video/' . $matches[1];
    }
@endphp
@if($embedUrl)
<div class="block-video">
    <iframe src="{{ $embedUrl }}" allowfullscreen loading="lazy"></iframe>
</div>
@endif
