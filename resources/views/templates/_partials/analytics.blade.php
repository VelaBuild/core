@php $gaId = config('services.google_analytics.id'); @endphp
@if($gaId)
    @if(config('vela.gdpr.enabled'))
        {{-- GDPR mode: defer GA until analytics consent is granted --}}
        <script>
        document.addEventListener('vela:consent:analytics', function() {
            if (window.__velaGaLoaded) return;
            window.__velaGaLoaded = true;
            var s = document.createElement('script');
            s.async = true;
            s.src = 'https://www.googletagmanager.com/gtag/js?id={{ $gaId }}';
            document.head.appendChild(s);
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            window.gtag = gtag;
            gtag('js', new Date());
            gtag('config', '{{ $gaId }}', { 'anonymize_ip': true });
        });
        </script>
    @else
        {{-- No GDPR: load GA immediately --}}
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '{{ $gaId }}');
        </script>
    @endif
@endif
