/**
 * What a code snippet does on the page: colours itself, copies itself, and
 * unfolds when it was too tall to show whole.
 *
 * The block already reads correctly without any of this — the words are in
 * the markup, the box scrolls, and a folded snippet scrolls to its end. So
 * everything here is added to what is there, and a highlighter that cannot
 * be fetched leaves plain, readable code behind.
 *
 * Colours come from page-blocks.css, not from a highlight.js theme: the block
 * has a dark and a light look of its own, and two stylesheets fighting over
 * one <code> is how a light snippet ends up with dark-theme colours.
 */
(function () {
    'use strict';

    var CDN = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js';
    var loading = null;

    function load() {
        if (window.hljs) return Promise.resolve(window.hljs);
        if (loading) return loading;

        loading = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = CDN;
            s.onload = function () { resolve(window.hljs); };
            s.onerror = reject;
            document.head.appendChild(s);
        }).catch(function () { return null; });

        return loading;
    }

    function paint(figure) {
        var name = figure.getAttribute('data-code-highlight');
        var body = figure.querySelector('.block-code-body');
        if (!name || !body || figure.hasAttribute('data-code-painted')) return;
        figure.setAttribute('data-code-painted', '');

        load().then(function (hljs) {
            if (!hljs || !hljs.getLanguage(name)) return;
            try {
                // The code is put back as text first: highlight() takes a
                // string, and textContent is the snippet exactly as saved.
                body.innerHTML = hljs.highlight(body.textContent, { language: name, ignoreIllegals: true }).value;
                figure.classList.add('is-painted');
            } catch (e) {
                // Left as plain text, which is what it already was.
            }
        });
    }

    // Only what a reader is about to see is painted, so a page of snippets
    // does not fetch and run the highlighter before anything is on screen.
    function watch() {
        var figures = [].slice.call(document.querySelectorAll('.block-code[data-code-highlight]'));
        if (!figures.length) return;

        if (!('IntersectionObserver' in window)) {
            figures.forEach(paint);
            return;
        }
        var seen = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                observer.unobserve(entry.target);
                paint(entry.target);
            });
        }, { rootMargin: '200px' });
        figures.forEach(function (f) { seen.observe(f); });
    }

    function say(button, text, className) {
        var label = button.querySelector('.block-code-copy-label') || button;
        label.textContent = text;
        button.classList.add(className);
        clearTimeout(button._velaCodeTimer);
        button._velaCodeTimer = setTimeout(function () {
            button.classList.remove(className);
            label.textContent = button.getAttribute('data-copy-label') || 'Copy';
        }, 1500);
    }

    // navigator.clipboard exists only on https (and localhost). Plenty of
    // sites are not there yet, and the button used to do nothing at all and
    // say nothing about it.
    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.cssText = 'position:fixed;top:0;left:-9999px;';
            document.body.appendChild(area);
            area.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            area.remove();
            ok ? resolve() : reject();
        });
    }

    document.addEventListener('click', function (e) {
        var button = e.target.closest('[data-code-copy]');
        if (button) {
            var figureFor = button.closest('.block-code');
            var body = figureFor && figureFor.querySelector('.block-code-body');
            copy(body ? body.textContent || '' : '').then(function () {
                say(button, button.getAttribute('data-copied-label') || 'Copied', 'is-copied');
            }, function () {
                say(button, button.getAttribute('data-failed-label') || 'Copy failed', 'is-failed');
            });
            return;
        }

        var more = e.target.closest('[data-code-more]');
        if (more) {
            var figure = more.closest('.block-code');
            var open = !figure.classList.contains('is-open');
            figure.classList.toggle('is-open', open);
            more.setAttribute('aria-expanded', open ? 'true' : 'false');
            more.textContent = (open ? more.getAttribute('data-less-label') : more.getAttribute('data-more-label')) || '';
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', watch);
    } else {
        watch();
    }
})();
