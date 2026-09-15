@php
    $content       = $block->content ?? [];
    $s             = \VelaBuild\Core\Services\Blocks\Cta::settings($block->settings ?? []);
    $heading       = (string) ($content['heading'] ?? '');
    $description   = $content['description'] ?? '';
    $note          = $content['note'] ?? '';
    $primaryText   = $content['primary_button_text'] ?? '';
    $primaryUrl    = $content['primary_button_url'] ?? '';
    $secondaryText = $content['secondary_button_text'] ?? '';
    $secondaryUrl  = $content['secondary_button_url'] ?? '';

    // Emphasis only, and no attributes on it: see Cta::heading().
    $safeHeading = \VelaBuild\Core\Services\Blocks\Cta::heading($heading);
    $look        = \VelaBuild\Core\Services\Blocks\Cta::look($s);
    $justify     = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$s['text_alignment']];
@endphp
<div class="{{ implode(' ', $look['classes']) }}" style="{{ $look['style'] }}">
    <div class="block-cta-inner">
        <div class="block-cta-words">
@if($heading !== '')
            <h2 class="block-cta-heading">{!! $safeHeading !!}</h2>
@endif
@if($description !== '')
            <p class="block-cta-description">{{ $description }}</p>
@endif
        </div>
@if($primaryText !== '' || $secondaryText !== '' || $note !== '')
        <div class="block-cta-side">
@if($primaryText !== '' || $secondaryText !== '')
            <div class="block-cta-actions" style="justify-content:{{ $justify }};">
@if($primaryText !== '')
                <a href="{{ $primaryUrl }}" class="block-cta-btn block-cta-btn-primary"{!! vela_external_link_attrs($primaryUrl) !!}>{{ $primaryText }}</a>
@endif
@if($secondaryText !== '')
                <a href="{{ $secondaryUrl }}" class="block-cta-btn block-cta-btn-secondary"{!! vela_external_link_attrs($secondaryUrl) !!}>{{ $secondaryText }}</a>
@endif
            </div>
@endif
@if($note !== '')
            <div class="block-cta-note">{{ $note }}</div>
@endif
        </div>
@endif
    </div>
</div>
