@php
    /**
     * A colour on its way into a stylesheet, or null.
     *
     * These values are written by whoever can reach Settings, and they were
     * interpolated into the <style> block below as they stood. Blade escapes
     * `<`, so the tag could not be closed, but nothing stopped a value of
     * `red; } body { display: none` from carrying its own rules into every
     * public page of the site. The grammar below is every colour the admin's
     * colour inputs can produce and nothing else: a hex, or one of the CSS
     * colour functions with only numbers, commas and percent signs inside it,
     * or a bare keyword.
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

    /**
     * Every overridable colour that has actually been set, as
     * option key => value. Driven by DesignTokens::SITE_OPTIONS rather than
     * by three names written out here, so a theme that offers a picker for
     * its surface colour has that picker do something.
     */
    $velaOverrides = [];

    foreach (\VelaBuild\Core\Services\DesignTokens::SITE_OPTIONS as $velaKey => $velaOption) {
        if ($velaValue = $velaCssColour(config('vela.theme.' . $velaKey))) {
            $velaOverrides[$velaKey] = $velaValue;
        }
    }
@endphp
@if($velaOverrides)
    {{-- Included last in every layout, so these outrank the theme's own
         palette. --}}
    <style id="vela-theme-colors">
        :root {
@foreach($velaOverrides as $velaKey => $velaValue)
            {{ \VelaBuild\Core\Services\DesignTokens::SITE_OPTIONS[$velaKey][0] }}: {{ $velaValue }};
@endforeach
@if(isset($velaOverrides['primary_color']))
            {{-- A brand colour has to carry its two companions with it: the
                 fill behind white text, and the hover. Setting --vela-primary
                 alone would leave the theme's own declared hover standing, so
                 a red brand would darken to the theme's purple on hover. --}}
            --vela-primary-fill: {{ $velaOverrides['primary_color'] }};
            --vela-primary-hover: color-mix(in srgb, {{ $velaOverrides['primary_color'] }} 85%, #000);
@endif
        }
    </style>
@endif
