/**
 * The gallery block's lightbox.
 *
 * Replaces an Alpine one that showed a single picture: to see the next you
 * closed it and opened another. This one moves through the gallery with
 * buttons, arrow keys and a swipe, says where you are, and gives focus back
 * to the picture it was opened from.
 *
 * Captions and addresses are read from data attributes as text, never from
 * a string of script — an apostrophe in a caption broke the old one.
 */
(function () {
    'use strict';

    var box = null;
    var state = { items: [], index: 0, opener: null };

    function el(tag, className, attrs) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        return node;
    }

    function build(labels) {
        box = el('div', 'vela-lightbox', { role: 'dialog', 'aria-modal': 'true', hidden: '' });
        box.innerHTML =
            '<button type="button" class="vela-lightbox-close">&times;</button>' +
            '<button type="button" class="vela-lightbox-nav vela-lightbox-prev">&#8249;</button>' +
            '<figure class="vela-lightbox-figure"><img class="vela-lightbox-img" alt="">' +
            '<figcaption class="vela-lightbox-caption"></figcaption></figure>' +
            '<button type="button" class="vela-lightbox-nav vela-lightbox-next">&#8250;</button>' +
            '<div class="vela-lightbox-count" aria-live="polite"></div>';
        document.body.appendChild(box);

        box.querySelector('.vela-lightbox-close').addEventListener('click', close);
        box.querySelector('.vela-lightbox-prev').addEventListener('click', function () { show(state.index - 1); });
        box.querySelector('.vela-lightbox-next').addEventListener('click', function () { show(state.index + 1); });
        // The dark around the picture closes it; the picture itself does not.
        box.addEventListener('click', function (e) { if (e.target === box || e.target.classList.contains('vela-lightbox-figure')) close(); });

        document.addEventListener('keydown', function (e) {
            if (box.hidden) return;
            if (e.key === 'Escape') close();
            else if (e.key === 'ArrowLeft') show(state.index - 1);
            else if (e.key === 'ArrowRight') show(state.index + 1);
            else if (e.key === 'Tab') {
                // Keep focus inside while it is open.
                var f = Array.prototype.filter.call(box.querySelectorAll('button'), function (b) { return !b.hidden; });
                var i = f.indexOf(document.activeElement);
                e.preventDefault();
                f[(i + (e.shiftKey ? -1 : 1) + f.length) % f.length].focus();
            }
        });

        var startX = null;
        box.addEventListener('pointerdown', function (e) { startX = e.clientX; });
        box.addEventListener('pointerup', function (e) {
            if (startX === null) return;
            var dx = e.clientX - startX;
            startX = null;
            if (Math.abs(dx) > 50) show(state.index + (dx < 0 ? 1 : -1));
        });
    }

    function label(labels) {
        box.querySelector('.vela-lightbox-close').setAttribute('aria-label', labels.close || 'Close');
        box.querySelector('.vela-lightbox-prev').setAttribute('aria-label', labels.previous || 'Previous');
        box.querySelector('.vela-lightbox-next').setAttribute('aria-label', labels.next || 'Next');
    }

    function show(i) {
        var n = state.items.length;
        state.index = (i + n) % n;
        var item = state.items[state.index];
        var img = box.querySelector('.vela-lightbox-img');
        img.src = item.src;
        img.alt = item.alt;
        box.querySelector('.vela-lightbox-caption').textContent = item.caption;
        box.querySelector('.vela-lightbox-count').textContent = n > 1 ? (state.index + 1) + ' / ' + n : '';
        box.querySelector('.vela-lightbox-prev').hidden = n < 2;
        box.querySelector('.vela-lightbox-next').hidden = n < 2;
        // The neighbours, so the next press shows a picture rather than a wait.
        [state.index - 1, state.index + 1].forEach(function (j) {
            if (n > 1) new Image().src = state.items[(j + n) % n].src;
        });
    }

    function open(gallery, index, opener) {
        var labels = { close: gallery.getAttribute('data-label-close'), previous: gallery.getAttribute('data-label-previous'), next: gallery.getAttribute('data-label-next') };
        if (!box) build();
        label(labels);
        state.items = Array.prototype.map.call(gallery.querySelectorAll('.gallery-open'), function (b) {
            var img = b.querySelector('img');
            return { src: b.getAttribute('data-full'), caption: b.getAttribute('data-caption') || '', alt: img ? img.alt : '' };
        });
        state.opener = opener;
        box.hidden = false;
        document.documentElement.classList.add('vela-lightbox-open');
        show(index);
        box.querySelector('.vela-lightbox-close').focus();
    }

    function close() {
        box.hidden = true;
        document.documentElement.classList.remove('vela-lightbox-open');
        box.querySelector('.vela-lightbox-img').removeAttribute('src');
        if (state.opener) state.opener.focus();
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-vela-gallery] .gallery-open');
        if (!btn) return;
        var gallery = btn.closest('[data-vela-gallery]');
        var buttons = Array.prototype.slice.call(gallery.querySelectorAll('.gallery-open'));
        open(gallery, buttons.indexOf(btn), btn);
    });
})();
