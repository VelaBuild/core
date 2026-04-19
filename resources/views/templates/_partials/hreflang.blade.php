@php
    // Map internal locale codes to valid BCP 47 / ISO 639-1 tags for hreflang.
    // URL prefix stays as-is; only the hreflang attribute is normalized.
    $velaHreflangMap = ['dk' => 'da'];
@endphp
    <!-- Canonical URL -->
    <link rel="canonical" href="@yield('canonical_url', request()->url())">

    <!-- Language Href Tags -->
@foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
    <link rel="alternate" hreflang="{{ $velaHreflangMap[$localeCode] ?? $localeCode }}" href="{{ LaravelLocalization::getLocalizedURL($localeCode) }}">
@endforeach
