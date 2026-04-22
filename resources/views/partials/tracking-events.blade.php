{{-- Flushes the server-queued ecommerce events to gtag/fbq. Include at the
     bottom of the body, AFTER tracking-head. Pages push events via the
     TrackingService::queueEvent() helper (or directly into the array). --}}
@php
    $__events = app(\VelaBuild\Core\Services\TrackingService::class)->pullQueue();
    $__adsId  = app(\VelaBuild\Core\Services\TrackingService::class)->googleAdsId();
    $__adsPurchaseLabel = app(\VelaBuild\Core\Services\TrackingService::class)->googleAdsPurchaseLabel();
@endphp
@if(!empty($__events) || true)
<script>
(function () {
    window.__velaTrack = window.__velaTrack || [];
    // Any server-queued events for THIS pageview.
    var seeded = @json($__events);
    seeded.forEach(function (e) { window.__velaTrack.push(e); });

    var ads  = @json($__adsId);
    var lbl  = @json($__adsPurchaseLabel);

    // Map GA4-style event names onto Meta Pixel event names.
    var META_MAP = {
        'view_item':       'ViewContent',
        'view_item_list':  'ViewContent',
        'add_to_cart':     'AddToCart',
        'view_cart':       'ViewCart',
        'begin_checkout':  'InitiateCheckout',
        'add_payment_info':'AddPaymentInfo',
        'purchase':        'Purchase',
    };

    function dispatchOne(evt) {
        var name = evt.event;
        var data = evt.data || {};

        // ── gtag (GA4) ─────────────────────────────────────────
        if (window.gtag) {
            try {
                window.gtag('event', name, data);
            } catch (e) { console && console.warn && console.warn('gtag err', e); }
        }

        // ── Google Ads purchase conversion ────────────────────
        if (ads && name === 'purchase' && window.gtag) {
            var sendTo = lbl ? (ads + '/' + lbl) : ads;
            try {
                window.gtag('event', 'conversion', {
                    send_to: sendTo,
                    value:           data.value,
                    currency:        data.currency,
                    transaction_id:  data.transaction_id,
                });
            } catch (e) {}
        }

        // ── Meta Pixel (fbq) ──────────────────────────────────
        if (window.fbq) {
            var fbName = META_MAP[name];
            if (fbName) {
                var fbPayload = {
                    content_ids:  (data.items || []).map(function (i) { return String(i.item_id || i.id); }),
                    content_type: 'product',
                    value:    data.value,
                    currency: data.currency,
                    contents: (data.items || []).map(function (i) {
                        return {
                            id:       String(i.item_id || i.id),
                            quantity: i.quantity || 1,
                            item_price: i.price,
                        };
                    }),
                };
                // Purchase needs `num_items` for some use cases; harmless otherwise.
                if (name === 'purchase' && data.items) {
                    fbPayload.num_items = data.items.reduce(function (n, i) { return n + (i.quantity || 1); }, 0);
                }
                var opts = data.event_id ? { eventID: data.event_id } : undefined;
                try {
                    window.fbq('track', fbName, fbPayload, opts);
                } catch (e) {}
            }
        }
    }

    // Apply Google's Enhanced Conversions identity (gtag user_data) once
    // gtag is available. Idempotent — safe to call repeatedly. Meta side
    // is handled in tracking-head via fbq('init', ID, AM) at marketing init.
    var __amGoogleApplied = false;
    function applyGoogleAdvancedMatching() {
        if (__amGoogleApplied) return;
        if (!window.gtag) return;
        var am = window.__velaAdvancedMatching;
        if (am && am.google) {
            try {
                window.gtag('set', 'user_data', am.google);
                __amGoogleApplied = true;
            } catch (e) {}
        }
    }

    function flush() {
        applyGoogleAdvancedMatching();
        while (window.__velaTrack.length) {
            var next = window.__velaTrack.shift();
            dispatchOne(next);
        }
    }

    // If pixels are already loaded, flush now. Otherwise tracking-head's
    // initAll() calls __velaDispatchQueue() after loading them.
    window.__velaDispatchQueue = flush;
    if (window.gtag || window.fbq) flush();

    // Also hook a DOM event for client-side actions (e.g. add-to-cart JS).
    document.addEventListener('vela:track', function (e) {
        if (e && e.detail && e.detail.event) {
            window.__velaTrack.push({ event: e.detail.event, data: e.detail.data || {} });
            flush();
        }
    });
})();
</script>
@endif
