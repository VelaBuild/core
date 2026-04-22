{{-- Place immediately after <body>. Currently only GTM needs a <noscript> iframe. --}}
@php
    $__gtm = app(\VelaBuild\Core\Services\TrackingService::class)->gtmId();
@endphp
@if($__gtm)
<noscript>
    <iframe src="https://www.googletagmanager.com/ns.html?id={{ $__gtm }}"
            height="0" width="0" style="display:none;visibility:hidden" title="GTM"></iframe>
</noscript>
@endif
