{{--
    Cookie Consent Banner — only rendered when config('vela.gdpr.enabled') is true.
    Include with: @include('vela::partials.cookie-consent')

    Consent is stored in a first-party cookie (default: vela_consent).
    When analytics consent is granted, the event 'vela:consent:analytics' is
    dispatched on `document` so scripts can react.
--}}
@if(config('vela.gdpr.enabled'))
@php
    $cookieName = config('vela.gdpr.cookie_name', 'vela_consent');
    $privacyUrl = config('vela.gdpr.privacy_url', '/privacy');
    $cookieDays = config('vela.gdpr.cookie_lifetime', 365);
@endphp
<div id="vela-consent" style="display:none">
    <div class="vc-bar" role="dialog" aria-label="Cookie consent">
        <div class="vc-inner">
            <div class="vc-text">
                <p>{{ __('vela::gdpr.banner_text') }}</p>
            </div>
            <div class="vc-actions">
                <button type="button" class="vc-btn vc-btn-accept" id="vc-accept-all">{{ __('vela::gdpr.accept_all') }}</button>
                <button type="button" class="vc-btn vc-btn-necessary" id="vc-necessary-only">{{ __('vela::gdpr.necessary_only') }}</button>
                <button type="button" class="vc-btn vc-btn-manage" id="vc-manage-toggle">{{ __('vela::gdpr.manage') }}</button>
                <a href="{{ url($privacyUrl) }}" class="vc-privacy-link">{{ __('vela::gdpr.privacy_link') }}</a>
            </div>

            {{-- Expandable category panel --}}
            <div class="vc-categories" id="vc-categories" style="display:none">
                <div class="vc-category">
                    <div class="vc-category-header">
                        <label class="vc-switch vc-switch-locked">
                            <input type="checkbox" checked disabled>
                            <span class="vc-slider"></span>
                        </label>
                        <div>
                            <strong>{{ __('vela::gdpr.cat_necessary') }}</strong>
                            <p>{{ __('vela::gdpr.cat_necessary_desc') }}</p>
                        </div>
                    </div>
                </div>

                <div class="vc-category">
                    <div class="vc-category-header">
                        <label class="vc-switch">
                            <input type="checkbox" id="vc-cat-functional" checked>
                            <span class="vc-slider"></span>
                        </label>
                        <div>
                            <strong>{{ __('vela::gdpr.cat_functional') }}</strong>
                            <p>{{ __('vela::gdpr.cat_functional_desc') }}</p>
                        </div>
                    </div>
                </div>

                <div class="vc-category">
                    <div class="vc-category-header">
                        <label class="vc-switch">
                            <input type="checkbox" id="vc-cat-analytics">
                            <span class="vc-slider"></span>
                        </label>
                        <div>
                            <strong>{{ __('vela::gdpr.cat_analytics') }}</strong>
                            <p>{{ __('vela::gdpr.cat_analytics_desc') }}</p>
                        </div>
                    </div>
                </div>

                <div class="vc-category-actions">
                    <button type="button" class="vc-btn vc-btn-accept" id="vc-save-prefs">{{ __('vela::gdpr.save') }}</button>
                </div>
            </div>

        </div>
    </div>
</div>

{{-- Styles live in vela::partials.cookie-consent-styles and must be included
     from each template's <head>. Keeping the markup/JS here below. --}}

<script>
(function() {
    var COOKIE = @json($cookieName);
    var DAYS   = {{ $cookieDays }};
    var el     = document.getElementById('vela-consent');
    if (!el) return;

    /* ── Cookie helpers ── */
    function getCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : null;
    }
    function setCookie(name, value, days) {
        var d = new Date(); d.setTime(d.getTime() + days * 864e5);
        document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }

    /* ── Parse stored consent ── */
    function parse(val) {
        if (!val) return null;
        try { return JSON.parse(val); } catch(e) { return null; }
    }

    /* ── Dispatch events so other scripts can react ── */
    function fireEvents(consent) {
        if (consent.analytics) document.dispatchEvent(new CustomEvent('vela:consent:analytics'));
        if (consent.functional) document.dispatchEvent(new CustomEvent('vela:consent:functional'));
    }

    /* ── Check existing consent ── */
    var existing = parse(getCookie(COOKIE));
    if (existing) {
        fireEvents(existing);
        return; /* banner stays hidden */
    }

    /* ── Show banner ── */
    el.style.display = '';
    requestAnimationFrame(function() { el.classList.add('vc-visible'); });

    function save(consent) {
        setCookie(COOKIE, JSON.stringify(consent), DAYS);
        el.classList.remove('vc-visible');
        setTimeout(function() { el.style.display = 'none'; }, 400);
        fireEvents(consent);
    }

    document.getElementById('vc-accept-all').addEventListener('click', function() {
        save({ necessary: true, functional: true, analytics: true });
    });

    document.getElementById('vc-necessary-only').addEventListener('click', function() {
        save({ necessary: true, functional: false, analytics: false });
    });

    var categoriesEl = document.getElementById('vc-categories');
    document.getElementById('vc-manage-toggle').addEventListener('click', function() {
        categoriesEl.style.display = categoriesEl.style.display === 'none' ? '' : 'none';
    });

    document.getElementById('vc-save-prefs').addEventListener('click', function() {
        save({
            necessary:  true,
            functional: document.getElementById('vc-cat-functional').checked,
            analytics:  document.getElementById('vc-cat-analytics').checked
        });
    });
})();
</script>
@endif
