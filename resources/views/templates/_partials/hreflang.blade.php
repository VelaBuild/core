<!-- Canonical URL -->
<link rel="canonical" href="@yield('canonical_url', request()->url())">

<!-- Language Href Tags -->
@foreach(LaravelLocalization::getSupportedLocales() as $localeCode => $properties)
    <link rel="alternate" hreflang="{{ $localeCode }}" href="{{ LaravelLocalization::getLocalizedURL($localeCode) }}" />
@endforeach
