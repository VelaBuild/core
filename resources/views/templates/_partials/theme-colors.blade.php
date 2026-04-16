@php
    $primary = config('vela.theme.primary_color');
    $secondary = config('vela.theme.secondary_color');
    $background = config('vela.theme.background_color');
    $hasOverrides = $primary || $secondary || $background;
@endphp
@if($hasOverrides)
<style id="vela-theme-colors">
:root {
    @if($primary)--vela-primary: {{ $primary }};@endif
    @if($secondary)--vela-secondary: {{ $secondary }};@endif
    @if($background)--vela-background: {{ $background }};@endif
}
</style>
@endif
