@php
    $images   = ($block->content)['images'] ?? [];
    $settings = $block->settings ?? [];
    $columns  = (int)($settings['columns'] ?? 3);
    $gap      = (int)($settings['gap'] ?? 10);
    $lightbox = $settings['lightbox'] ?? true;
    $total    = count($images);
@endphp
@if($total > 0)
<div class="block-gallery"
    x-data="{
        lightboxOpen: false,
        lightboxSrc: '',
        lightboxCaption: '',
        openLightbox(src, caption) { this.lightboxSrc = src; this.lightboxCaption = caption; this.lightboxOpen = true; },
        closeLightbox() { this.lightboxOpen = false; this.lightboxSrc = ''; this.lightboxCaption = ''; }
    }"
    @keydown.escape.window="closeLightbox()">
    <div class="gallery-grid" style="display:grid;grid-template-columns:repeat({{ $columns }},1fr);gap:{{ $gap }}px;">
@foreach($images as $img)
@if(!empty($img['url']))
        <div class="gallery-item">
@if($lightbox)
            {!! vela_image($img['url'], $img['alt'] ?? '', [320, 640, 960, 1280], 'fit', [
                'style'  => 'width:100%;display:block;cursor:pointer;',
                '@click' => "openLightbox('" . e($img['url']) . "', '" . e($img['caption'] ?? '') . "')",
            ]) !!}
@else
                {!! vela_image($img['url'], $img['alt'] ?? '', [320, 640, 960, 1280], 'fit', ['style' => 'width:100%;display:block;']) !!}
@endif
@if(!empty($img['caption']))
                <div class="gallery-caption">{{ $img['caption'] }}</div>
@endif
            </div>
@endif
@endforeach
        </div>

@if($lightbox)
        <div x-show="lightboxOpen"
            x-cloak
            style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;"
            @click.self="closeLightbox()">
            <button @click="closeLightbox()"
                style="position:absolute;top:20px;right:20px;background:none;border:none;color:#fff;font-size:2em;line-height:1;cursor:pointer;"
                aria-label="Close">&times;</button>
            <img :src="lightboxSrc" :alt="lightboxCaption" style="max-width:90vw;max-height:80vh;object-fit:contain;border-radius:4px;">
            <div x-show="lightboxCaption" style="color:#fff;margin-top:12px;font-size:0.95em;text-align:center;" x-text="lightboxCaption"></div>
        </div>
@endif
    </div>
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-images',
        'title'   => trans('vela::global.gallery_empty_title'),
        'message' => trans('vela::global.gallery_empty_message'),
    ])
@endif
