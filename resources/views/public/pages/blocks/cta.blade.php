@php
    $content       = $block->content ?? [];
    $settings      = $block->settings ?? [];
    $heading       = $content['heading'] ?? '';
    $description   = $content['description'] ?? '';
    $note          = $content['note'] ?? '';
    $primaryText   = $content['primary_button_text'] ?? '';
    $primaryUrl    = $content['primary_button_url'] ?? '';
    $secondaryText = $content['secondary_button_text'] ?? '';
    $secondaryUrl  = $content['secondary_button_url'] ?? '';
    $alignment     = $settings['text_alignment'] ?? 'center';

    // Heading allows <em> and <strong> only — keep it safe while supporting the
    // editorial italic flourish templates like the Vela brand use.
    $safeHeading = strip_tags($heading, '<em><strong><i><b>');
@endphp
<div class="block-cta" style="text-align:{{ $alignment }};">
    <div class="block-cta-inner">
@if($heading !== '')
        <h2 class="block-cta-heading">{!! $safeHeading !!}</h2>
@endif
@if($description !== '')
        <p class="block-cta-description">{{ $description }}</p>
@endif
@if($primaryText !== '' || $secondaryText !== '')
        <div class="block-cta-actions" style="justify-content:{{ $alignment }};">
@if($primaryText !== '')
            <a href="{{ $primaryUrl }}" class="block-cta-btn block-cta-btn-primary">{{ $primaryText }}</a>
@endif
@if($secondaryText !== '')
            <a href="{{ $secondaryUrl }}" class="block-cta-btn block-cta-btn-secondary">{{ $secondaryText }}</a>
@endif
        </div>
@endif
@if($note !== '')
        <div class="block-cta-note">{{ $note }}</div>
@endif
    </div>
</div>
