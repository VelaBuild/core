@php
    // Map internal locale codes to valid BCP 47 / ISO 639-1 tags for hreflang.
    // URL prefix stays as-is; only the hreflang attribute is normalized.
    $velaHreflangMap = ['dk' => 'da'];

    // StaticSiteGenerator shares $canonicalUrl so pages pre-rendered from an
    // admin request (e.g. /admin/cache/clear) still get the correct URL baked in.
    $canonicalUrl = $canonicalUrl ?? request()->url();
@endphp
    <!-- Canonical URL -->
    <link rel="canonical" href="@yield('canonical_url', $canonicalUrl)">

    <!-- Language Href Tags -->
@foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
    <link rel="alternate" hreflang="{{ $velaHreflangMap[$localeCode] ?? $localeCode }}" href="{{ LaravelLocalization::getLocalizedURL($localeCode, $canonicalUrl) }}">
@endforeach
