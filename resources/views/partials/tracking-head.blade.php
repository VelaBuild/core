{{-- Tracking pixels + conversion tags.

     GDPR-gated on TWO separate consent categories:
       - `analytics` → GA4, GTM (measurement)
       - `marketing` → Meta Pixel, Google Ads (advertising)

     Without GDPR enabled, everything loads immediately. With GDPR enabled,
     each group waits on its own consent event. The unified dispatcher
     (tracking-events.blade.php) handles flushing the queue when either
     side becomes ready. --}}
@php
    $__svc = app(\VelaBuild\Core\Services\TrackingService::class);
    $__t = $__svc->headConfig();
    $__ga4  = $__t['ga4_id'];
    $__gtm  = $__t['gtm_id'];
    $__fb   = $__t['meta_pixel_id'];
    $__ads  = $__t['google_ads_id'];
    $__gate = $__t['consent_gate'];
    $__am   = $__svc->getAdvancedMatching();
@endphp

@if($__ga4 || $__gtm || $__fb || $__ads)
<script>
// Advanced Matching identity (set by the page when the customer is known
// — e.g. checkout success). Defined BEFORE pixel init so initMarketing()
// can pass the Meta-shaped block to fbq('init', ID, AM). Null otherwise.
window.__velaAdvancedMatching = @json($__am ?? null);
</script>
<script>
(function () {
    var gate = @json($__gate);
    var ga4  = @json($__ga4);
    var gtm  = @json($__gtm);
    var fb   = @json($__fb);
    var ads  = @json($__ads);

    function loadScript(src, asyncFlag) {
        var s = document.createElement('script');
        s.src = src;
        if (asyncFlag) s.async = true;
        document.head.appendChild(s);
    }

    // ── Analytics group (GA4 + GTM — measurement only) ─────────────
    function initAnalytics() {
        if (window.__velaAnalyticsInit) return;
        window.__velaAnalyticsInit = true;

        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };

        if (ga4) {
            loadScript('https://www.googletagmanager.com/gtag/js?id=' + ga4, true);
            gtag('js', new Date());
            gtag('config', ga4, { anonymize_ip: true });
        }

        if (gtm) {
            (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
                new Date().getTime(), event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
                j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;
                j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;
                f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer', gtm);
        }

        if (window.__velaDispatchQueue) window.__velaDispatchQueue();
    }

    // ── Marketing group (Meta Pixel + Google Ads — advertising) ────
    function initMarketing() {
        if (window.__velaMarketingInit) return;
        window.__velaMarketingInit = true;

        // Ensure gtag stub exists — Google Ads uses it too.
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };

        // Google Ads. If GA4 isn't active, we need to load gtag.js via Ads.
        if (ads) {
            if (!ga4 || !window.__velaAnalyticsInit) {
                loadScript('https://www.googletagmanager.com/gtag/js?id=' + ads, true);
                gtag('js', new Date());
            }
            gtag('config', ads);
        }

        if (fb) {
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){
                n.callMethod? n.callMethod.apply(n,arguments):n.queue.push(arguments)};
                if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
                n.queue=[];t=b.createElement(e);t.async=!0;
                t.src=v;s=b.getElementsByTagName(e)[0];
                s.parentNode.insertBefore(t,s)}(window,document,'script',
                'https://connect.facebook.net/en_US/fbevents.js');

            // If Advanced Matching data was queued server-side, init with it
            // so subsequent events (Purchase, AddToCart) are enhanced.
            if (window.__velaAdvancedMatching && window.__velaAdvancedMatching.meta) {
                fbq('init', fb, window.__velaAdvancedMatching.meta);
            } else {
                fbq('init', fb);
            }
            fbq('track', 'PageView');
        }

        if (window.__velaDispatchQueue) window.__velaDispatchQueue();
    }

    if (gate) {
        document.addEventListener('vela:consent:analytics', initAnalytics, { once: true });
        document.addEventListener('vela:consent:marketing', initMarketing, { once: true });
    } else {
        initAnalytics();
        initMarketing();
    }
})();
</script>
@endif
