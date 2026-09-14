@php
    $images   = ($block->content)['images'] ?? [];
    // A gallery is only the pictures it actually has. "#" is what arrives
    // when something built the block before it had any — a placeholder href
    // that is not empty, so it passed the check below and rendered as a row
    // of broken-image icons with the alt text showing through.
    $images   = array_values(array_filter($images, function ($img) {
        $url = trim((string) ($img['url'] ?? ''));

        return $url !== '' && $url !== '#';
    }));
    $settings = $block->settings ?? [];
    $columns  = min(6, max(1, (int) ($settings['columns'] ?? 3)));
    $gap      = min(64, max(0, (int) ($settings['gap'] ?? 10)));
    $lightbox = (bool) ($settings['lightbox'] ?? true);
    $total    = count($images);

    // grid: every picture cropped to one shape, so rows line up. It is also
    // what a gallery saved before there was a choice gets — those showed each
    // picture at its own shape, and rows of mixed heights were the complaint.
    // masonry: nothing cropped, columns of pictures at their own shapes.
    // featured: the first picture large, the rest cropped around it.
    $layout = in_array($settings['layout'] ?? '', ['grid', 'masonry', 'featured'], true) ? $settings['layout'] : 'grid';

    $ratios = ['1:1' => '1 / 1', '4:3' => '4 / 3', '3:2' => '3 / 2', '16:9' => '16 / 9', '4:5' => '4 / 5', '2:3' => '2 / 3'];
    $ratio  = $ratios[$settings['ratio'] ?? ''] ?? '1 / 1';

    // Where a crop keeps the picture: a face near the top survives `top`.
    $focus = in_array($settings['focus'] ?? '', ['center', 'top', 'bottom'], true) ? $settings['focus'] : 'center';

    $style = '--gallery-columns: ' . $columns . '; --gallery-columns-small: ' . min(2, $columns) . '; --gallery-gap: ' . $gap . 'px; --gallery-ratio: ' . $ratio . '; --gallery-focus: ' . $focus . ';';
@endphp
@if($total > 0)
@if($lightbox)
@once
<script src="{{ asset('vendor/vela/js/vela-gallery.js') }}?v={{ is_file(public_path('vendor/vela/js/vela-gallery.js')) ? filemtime(public_path('vendor/vela/js/vela-gallery.js')) : '1' }}" defer></script>
@endonce
@endif
<div class="block-gallery block-gallery--{{ $layout }}"@if($lightbox) data-vela-gallery data-label-close="{{ trans('vela::global.gallery_close') }}" data-label-previous="{{ trans('vela::global.carousel_previous') }}" data-label-next="{{ trans('vela::global.carousel_next') }}"@endif style="{{ $style }}">
    <div class="gallery-grid">
@foreach($images as $i => $img)
@php
    $alt     = (string) ($img['alt'] ?? '');
    $caption = trim((string) ($img['caption'] ?? ''));
    // Large enough for the space it takes: a featured picture is two columns wide.
    $sizes   = $layout === 'featured' && $i === 0 ? [640, 960, 1280, 1920] : [320, 640, 960, 1280];
    $picture = vela_image($img['url'], $alt, $sizes, 'fit', ['class' => 'gallery-image'], $i < $columns ? 'eager' : 'lazy');
@endphp
        <figure class="gallery-item{{ $layout === 'featured' && $i === 0 ? ' gallery-item--featured' : '' }}">
@if($lightbox)
            {{-- The address and caption travel as attributes and are read back
                 as text. They used to be written into an Alpine @click string,
                 where an apostrophe in a caption ("Chef's table") ended the
                 string and broke the lightbox — and let a caption run script. --}}
            <button type="button" class="gallery-open" data-full="{{ $img['url'] }}" data-caption="{{ $caption }}"
                aria-label="{{ trans('vela::global.gallery_open_image', ['n' => $i + 1, 'total' => $total]) }}">
                {!! $picture !!}
            </button>
@else
            <div class="gallery-frame">{!! $picture !!}</div>
@endif
@if($caption !== '')
            <figcaption class="gallery-caption">{{ $caption }}</figcaption>
@endif
        </figure>
@endforeach
    </div>
</div>
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-images',
        'title'   => trans('vela::global.gallery_empty_title'),
        'message' => trans('vela::global.gallery_empty_message'),
    ])
@endif
