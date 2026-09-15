@php
    $content       = $block->content ?? [];
    $s             = \VelaBuild\Core\Services\Blocks\Hero::settings($block->settings ?? []);
    $eyebrow       = trim((string) ($content['eyebrow'] ?? ''));
    $title         = $content['title'] ?? '';
    $subtitle      = $content['subtitle'] ?? '';
    $primaryText   = $content['primary_button_text'] ?? '';
    $primaryUrl    = $content['primary_button_url'] ?? '';
    $secondaryText = $content['secondary_button_text'] ?? '';
    $secondaryUrl  = $content['secondary_button_url'] ?? '';
    $alignment     = $s['text_alignment'];

    // The picture is drawn here rather than as the block wrapper's CSS
    // background (page-rows.blade.php leaves a hero's out), so it can have
    // sizes for each screen, a point to keep in frame, and a high priority.
    $image      = trim((string) ($block->background_image ?? ''));
    $isFirst    = \VelaBuild\Core\Services\Blocks\Hero::claimFirst();
    $heading    = $s['heading_level'] === 'auto' ? ($isFirst ? 'h1' : 'h2') : $s['heading_level'];
    $ink        = \VelaBuild\Core\Services\Blocks\Hero::ink($s, $image !== '');
    $focus      = $s['focal_x'] . '% ' . $s['focal_y'] . '%';

    $classes = ['block-hero', 'block-hero--x-' . $alignment, 'block-hero--y-' . $s['vertical_align'], 'block-hero--ink-' . $ink, 'block-hero--btn-' . $s['button_style']];
    if ($image !== '') {
        $classes[] = 'has-media';
    }
    $style = 'text-align:' . $alignment . ';' . ($s['min_height'] !== 'auto' ? 'min-height:' . $s['min_height'] . ';' : '');
    if ($btn = \VelaBuild\Core\Services\DesignTokens::colour($s['button_color'])) {
        $style .= '--hero-btn-bg:' . $btn . ';';
        $classes[] = 'has-btn-color';
        if ($btnInk = \VelaBuild\Core\Services\DesignTokens::inkFor($s['button_color'])) {
            $style .= '--hero-btn-ink:' . $btnInk . ';';
        }
    }
    $justify = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$alignment];
    $overlayCss = \VelaBuild\Core\Services\Blocks\Hero::overlayCss($s);
@endphp
<div class="{{ implode(' ', $classes) }}" style="{{ $style }}">
@if($image !== '')
    <picture class="block-hero-media">
@if($s['mobile_background_image'] !== '')
        <source media="(max-width: 640px)" sizes="100vw" srcset="{{ collect(\VelaBuild\Core\Services\Blocks\Hero::widths($s['mobile_background_image']))->filter(fn ($w) => $w <= 1280)->map(fn ($w) => vela_image_url($s['mobile_background_image'], $w) . ' ' . $w . 'w')->implode(', ') ?: vela_image_url($s['mobile_background_image'], 640) . ' 640w' }}">
@endif
        {!! vela_image($image, '', \VelaBuild\Core\Services\Blocks\Hero::widths($image), 'fit', ['class' => 'block-hero-media-img', 'sizes' => '100vw', 'style' => 'object-position:' . $focus], $isFirst ? 'preload' : 'lazy') !!}
    </picture>
@endif
@if($overlayCss !== '')
    <div class="block-hero-overlay" style="{{ $overlayCss }}"></div>
@endif
    <div class="block-hero-inner">
@if($eyebrow !== '')
        <p class="block-hero-eyebrow">{{ $eyebrow }}</p>
@endif
@if($title)
        <{{ $heading }} class="block-hero-title">{{ $title }}</{{ $heading }}>
@endif
@if($subtitle)
        <p class="block-hero-subtitle">{{ $subtitle }}</p>
@endif
@if($primaryText || $secondaryText)
        <div class="block-hero-actions" style="justify-content:{{ $justify }};">
@if($primaryText)
            <a href="{{ $primaryUrl }}" class="block-hero-btn block-hero-btn-primary"{!! vela_external_link_attrs($primaryUrl) !!}>{{ $primaryText }}</a>
@endif
@if($secondaryText)
            <a href="{{ $secondaryUrl }}" class="block-hero-btn block-hero-btn-secondary"{!! vela_external_link_attrs($secondaryUrl) !!}>{{ $secondaryText }}</a>
@endif
        </div>
@endif
    </div>
</div>
