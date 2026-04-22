@php
    $content = $block->content ?? [];
    $settings = $block->settings ?? [];
    $title = $content['title'] ?? '';
    $subtitle = $content['subtitle'] ?? '';
    $primaryText = $content['primary_button_text'] ?? '';
    $primaryUrl = $content['primary_button_url'] ?? '';
    $secondaryText = $content['secondary_button_text'] ?? '';
    $secondaryUrl = $content['secondary_button_url'] ?? '';
    $alignment = $settings['text_alignment'] ?? 'center';
    $minHeight = $settings['min_height'] ?? '80vh';
@endphp
@if($title || $subtitle || $primaryText || $secondaryText)
    <div style="background:#1a1a2e; color:#fff; border-radius:4px; padding:20px; text-align:{{ $alignment }};">
        @if($title)
            <h4 style="margin:0 0 4px;">{{ $title }}</h4>
        @endif
        @if($subtitle)
            <p style="margin:0 0 8px; opacity:0.8;">{{ $subtitle }}</p>
        @endif
        @if($primaryText || $secondaryText)
            <div>
                @if($primaryText)
                    <span class="badge badge-primary">{{ $primaryText }}</span>
                    @if($primaryUrl) <small class="text-muted" style="color:#aaa !important;">{{ $primaryUrl }}</small> @endif
                @endif
                @if($secondaryText)
                    <span class="badge badge-secondary ml-1">{{ $secondaryText }}</span>
                    @if($secondaryUrl) <small class="text-muted" style="color:#aaa !important;">{{ $secondaryUrl }}</small> @endif
                @endif
            </div>
        @endif
        <small style="opacity:0.5;">min-height: {{ $minHeight }}</small>
    </div>
@else
    <em class="text-muted">{{ trans('vela::global.empty_block') }}</em>
@endif
