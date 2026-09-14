/**
 * The carousel block: moves between slides, one or several at a time.
 *
 * Replaces the Alpine component the block used to be. That one showed a
 * slide with `x-show`, which is display:none — so the transition written on
 * it never ran, and pictures cut rather than moved. It also could not be
 * swiped, kept turning while someone was reading a slide, and turned again a
 * moment after a click because its timer never heard about the click.
 *
 * Markup is in resources/views/public/pages/blocks/carousel.blade.php; every
 * `[data-vela-carousel]` on the page is started once.
 */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Fewer at a time on a smaller screen: four cards in a phone's width are
    // four pictures nobody can see.
    function perViewFor(wanted) {
        var w = window.innerWidth;
        if (w < 640) return 1;
        if (w < 1024) return Math.min(wanted, 2);
        return wanted;
    }

    function start(root) {
        if (root.velaCarousel) return;

        var track = root.querySelector('.carousel-track');
        var slides = Array.prototype.slice.call(root.querySelectorAll('.carousel-slide'));
        var dotsBox = root.querySelector('.carousel-dots');
        var prevBtn = root.querySelector('.carousel-prev');
        var nextBtn = root.querySelector('.carousel-next');
        var fade = root.classList.contains('block-carousel--fade');
        var wanted = parseInt(root.getAttribute('data-per-view'), 10) || 1;
        var interval = parseInt(root.getAttribute('data-interval'), 10) || 5000;
        var autoplay = root.getAttribute('data-autoplay') === '1' && !reduceMotion;

        var index = 0;
        var perView = perViewFor(wanted);
        var timer = null;
        var paused = false;

        function lastIndex() { return Math.max(0, slides.length - perView); }

        function drawDots() {
            if (!dotsBox) return;
            var count = lastIndex() + 1;
            if (dotsBox.children.length !== count) {
                dotsBox.innerHTML = '';
                for (var d = 0; d < count; d++) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'carousel-dot';
                    b.setAttribute('aria-label', String(d + 1));
                    dotsBox.appendChild(b);
                }
            }
            Array.prototype.forEach.call(dotsBox.children, function (dot, d) {
                dot.classList.toggle('active', d === index);
                if (d === index) dot.setAttribute('aria-current', 'true');
                else dot.removeAttribute('aria-current');
            });
            // Nothing to move between once they all fit.
            dotsBox.hidden = count < 2;
        }

        function go(to) {
            var last = lastIndex();
            index = to > last ? 0 : (to < 0 ? last : to);
            root.style.setProperty('--carousel-index', index);
            root.style.setProperty('--carousel-per-view-now', perView);

            slides.forEach(function (slide, i) {
                var shown = i >= index && i < index + perView;
                slide.classList.toggle('is-active', shown);
                // Hidden slides keep their links out of the tab order.
                if (shown) { slide.removeAttribute('aria-hidden'); slide.removeAttribute('inert'); }
                else { slide.setAttribute('aria-hidden', 'true'); slide.setAttribute('inert', ''); }
            });

            if (prevBtn) prevBtn.hidden = last === 0;
            if (nextBtn) nextBtn.hidden = last === 0;
            drawDots();
        }

        function stop() { clearInterval(timer); timer = null; }
        function run() {
            stop();
            if (autoplay && !paused && lastIndex() > 0) {
                timer = setInterval(function () { go(index + 1); }, interval);
            }
        }
        // Moved by hand: the next turn is a whole interval away, not whatever
        // was left of the last one.
        function goByHand(to) { go(to); run(); }

        if (prevBtn) prevBtn.addEventListener('click', function () { goByHand(index - 1); });
        if (nextBtn) nextBtn.addEventListener('click', function () { goByHand(index + 1); });
        if (dotsBox) dotsBox.addEventListener('click', function (e) {
            var dot = e.target.closest('.carousel-dot');
            if (dot) goByHand(Array.prototype.indexOf.call(dotsBox.children, dot));
        });

        // Held still while someone is pointing at it or working inside it.
        function hold(on) { paused = on; run(); }
        root.addEventListener('mouseenter', function () { hold(true); });
        root.addEventListener('mouseleave', function () { hold(false); });
        root.addEventListener('focusin', function () { hold(true); });
        root.addEventListener('focusout', function (e) { if (!root.contains(e.relatedTarget)) hold(false); });
        document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else run(); });

        root.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') { e.preventDefault(); goByHand(index - 1); }
            else if (e.key === 'ArrowRight') { e.preventDefault(); goByHand(index + 1); }
        });

        // Swipe, and drag with a mouse. The track follows the finger while it
        // moves (not in a fade, which has nowhere to follow to) and settles on
        // the nearest slide; a drag that went anywhere is not also a click on
        // the link underneath.
        var startX = null, startY = null, dx = 0, dragging = false, moved = false;
        var viewport = root.querySelector('.carousel-viewport');

        viewport.addEventListener('pointerdown', function (e) {
            if (e.button !== 0 || e.target.closest('.carousel-arrow, .carousel-button')) return;
            startX = e.clientX; startY = e.clientY; dx = 0; dragging = false; moved = false;
        });
        viewport.addEventListener('pointermove', function (e) {
            if (startX === null) return;
            dx = e.clientX - startX;
            if (!dragging) {
                // Mostly sideways, or it is the page being scrolled.
                if (Math.abs(dx) < 8 || Math.abs(dx) < Math.abs(e.clientY - startY)) return;
                dragging = true; moved = true;
                root.classList.add('is-dragging');
                try { viewport.setPointerCapture(e.pointerId); } catch (err) { /* already released */ }
                stop();
            }
            if (!fade) root.style.setProperty('--carousel-drag', dx + 'px');
        });
        function release() {
            if (startX === null) return;
            var width = viewport.clientWidth / perView;
            if (dragging) {
                root.classList.remove('is-dragging');
                root.style.setProperty('--carousel-drag', '0px');
                var steps = Math.abs(dx) > Math.min(60, width * 0.2) ? Math.max(1, Math.round(Math.abs(dx) / width)) : 0;
                goByHand(index + (dx < 0 ? steps : -steps));
            }
            startX = null; dragging = false;
        }
        viewport.addEventListener('pointerup', release);
        viewport.addEventListener('pointercancel', release);
        viewport.addEventListener('click', function (e) {
            if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; }
        }, true);
        // A dragged picture would otherwise be picked up by the browser itself.
        viewport.addEventListener('dragstart', function (e) { e.preventDefault(); });

        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                var now = perViewFor(wanted);
                if (now !== perView) { perView = now; go(Math.min(index, lastIndex())); run(); }
            }, 120);
        });

        root.velaCarousel = { go: goByHand, get index() { return index; } };
        root.classList.add('is-ready');
        go(0);
        run();
    }

    function startAll() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-vela-carousel]'), start);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startAll);
    else startAll();
})();
