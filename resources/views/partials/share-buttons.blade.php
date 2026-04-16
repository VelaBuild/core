@php
    $shareUrl = $shareUrl ?? request()->url();
    $shareTitle = $shareTitle ?? '';
    $shareText = $shareText ?? '';
@endphp

<div class="vela-share-buttons" data-url="{{ $shareUrl }}" data-title="{{ $shareTitle }}" data-text="{{ $shareText }}">
    {{-- Web Share API button (shown via JS if supported) --}}
    <button class="vela-share-native" style="display:none;" aria-label="Share">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
        {{ __('vela::pwa.share') }}
    </button>

    {{-- Fallback social links (hidden if Web Share API is available) --}}
    <div class="vela-share-fallback">
        <a href="mailto:?subject={{ urlencode($shareTitle) }}&body={{ urlencode($shareUrl) }}" title="Email" rel="noopener">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
        </a>
        <a href="https://twitter.com/intent/tweet?url={{ urlencode($shareUrl) }}&text={{ urlencode($shareTitle) }}" target="_blank" rel="noopener" title="Twitter">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        </a>
        <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($shareUrl) }}" target="_blank" rel="noopener" title="Facebook">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
        </a>
    </div>
</div>

<script>
(function() {
    if (!navigator.share) return;
    document.querySelectorAll('.vela-share-buttons').forEach(function(el) {
        var nativeBtn = el.querySelector('.vela-share-native');
        var fallback = el.querySelector('.vela-share-fallback');
        if (nativeBtn) nativeBtn.style.display = 'inline-flex';
        if (fallback) fallback.style.display = 'none';
        nativeBtn.addEventListener('click', function() {
            navigator.share({
                title: el.dataset.title,
                text: el.dataset.text,
                url: el.dataset.url
            }).catch(function() {});
        });
    });
})();
</script>
