@php
    /**
     * A colour on its way into a stylesheet, or null.
     *
     * These three values are written by whoever can reach Settings, and they
     * were interpolated into the <style> block below as they stood. Blade
     * escapes `<`, so the tag could not be closed, but nothing stopped a
     * value of `red; } body { display: none` from carrying its own rules into
     * every public page of the site. The grammar below is every colour the
     * admin's colour inputs can produce and nothing else: a hex, or one of
     * the CSS colour functions with only numbers, commas and percent signs
     * inside it, or a bare keyword.
     */
    $velaCssColour = function ($value) {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $ok = preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value)
            || preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/deg-]+\)$/i', $value)
            || preg_match('/^[a-z]{3,20}$/i', $value);

        return $ok ? $value : null;
    };

    $primary      = $velaCssColour(config('vela.theme.primary_color'));
    $secondary    = $velaCssColour(config('vela.theme.secondary_color'));
    $background   = $velaCssColour(config('vela.theme.background_color'));
    $hasOverrides = $primary || $secondary || $background;
@endphp
@if($hasOverrides)
    {{-- Included last in every layout, so these outrank the theme's own
         palette. A brand colour has to carry its two companions with it: the
         fill behind white text, and the hover. Setting `--vela-primary` alone
         would leave the theme's own declared hover standing, so a red brand
         would darken to the theme's purple on hover. --}}
    <style id="vela-theme-colors">
        :root {
@if($primary)
            --vela-primary: {{ $primary }};
            --vela-primary-fill: {{ $primary }};
            --vela-primary-hover: color-mix(in srgb, {{ $primary }} 85%, #000);
@endif
@if($secondary)
            --vela-secondary: {{ $secondary }};
@endif
@if($background)
            --vela-background: {{ $background }};
@endif
        }
    </style>
@endif
