@php
    $content = $block->content;
    $url = $content['url'] ?? '';
    $embedUrl = '';
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $matches)) {
        $embedUrl = 'https://www.youtube.com/embed/' . $matches[1];
    } elseif (preg_match('/vimeo\.com\/(?:video\/)?(\d+)/', $url, $matches)) {
        $embedUrl = 'https://player.vimeo.com/video/' . $matches[1];
    }
@endphp
@if($embedUrl)
    <div style="position:relative;padding-bottom:56.25%;height:0;max-width:400px;">
        <iframe src="{{ $embedUrl }}" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none;" allowfullscreen></iframe>
    </div>
@elseif($url)
    <small class="text-muted">{{ trans('vela::global.video_url_label') }} {{ $url }}</small>
@else
    <em class="text-muted">{{ trans('vela::global.no_video_url') }}</em>
@endif
