{{-- Styles for the render-time interactive markup driven by scripts-footer.
     They live in <head> because a <style> element is not valid inside <body>;
     the behaviour they dress stays in the footer partial. --}}

{{-- A copied carousel loses the script that moved it; the track is marked
     at render time and driven as a scroll-snapping strip, which swipes on a
     phone and scrolls on a trackpad without any of it. --}}
<style>[data-vela-carousel-track]{display:flex!important;flex-wrap:nowrap!important;overflow-x:auto!important;white-space:normal!important;scroll-snap-type:x mandatory;scroll-behavior:smooth;-webkit-overflow-scrolling:touch;scrollbar-width:none}[data-vela-carousel-track]::-webkit-scrollbar{display:none}[data-vela-slide]{flex:0 0 auto!important;scroll-snap-align:start}[data-vela-carousel-prev],[data-vela-carousel-next]{cursor:pointer}[data-vela-carousel-dots]{display:flex;gap:8px;justify-content:center;align-items:center}[data-vela-carousel-dot]{width:8px;height:8px;padding:0;border:0;border-radius:999px;background:currentColor;opacity:.35;cursor:pointer;transition:opacity .2s ease}[data-vela-carousel-dot][aria-current="true"]{opacity:1}@media (prefers-reduced-motion:reduce){[data-vela-carousel-track]{scroll-behavior:auto}}</style>

{{-- The chevron in a copied FAQ turned through the source site's own
     animation code, which was stripped with its scripts. !important beats
     the inline transform such exports leave on the arrow. --}}
<style>[data-vela-disclosure] [class*="arrow"],[data-vela-disclosure] [class*="chevron"],[data-vela-disclosure] [class*="caret"]{transition:transform .2s ease}[data-vela-disclosure][aria-expanded="true"] [class*="arrow"],[data-vela-disclosure][aria-expanded="true"] [class*="chevron"],[data-vela-disclosure][aria-expanded="true"] [class*="caret"]{transform:rotate(180deg) !important}</style>
