/**
 * Turns every `textarea.vela-code-input` into a code editor: coloured syntax,
 * suggestions as you type (tags and attributes in HTML, properties in CSS),
 * tags that close themselves, and Tab that indents.
 *
 * The textarea stays the field of record. Everything that already reads or
 * writes it — page-editor.js's `$('#html-content').val()`, the form submit,
 * the "rebuild the form when the markup changes" listener — keeps working,
 * because the editor writes every change back into it and a `.val(x)` from
 * outside is passed on to the editor.
 *
 * CodeMirror is fetched only on a page that has such a field, and if it
 * cannot be fetched the textarea is simply left as it is: dark, monospaced,
 * and still usable.
 *
 * Which language a field is in:
 *   data-code-mode="html|css|js|json|php|bash|sql|yaml|text"
 *   data-code-mode-from="#some-select"   follow a select's value instead
 */
(function () {
    'use strict';

    var CDN = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/';
    var STYLES = ['codemirror.min.css', 'addon/hint/show-hint.min.css'];
    // The core first; the rest only register themselves with it, so their
    // order among each other does not matter, but loading one at a time keeps
    // that from ever being a question.
    var SCRIPTS = [
        'codemirror.min.js',
        'mode/xml/xml.min.js',
        'mode/javascript/javascript.min.js',
        'mode/css/css.min.js',
        'mode/htmlmixed/htmlmixed.min.js',
        'mode/clike/clike.min.js',
        'mode/php/php.min.js',
        'mode/shell/shell.min.js',
        'mode/sql/sql.min.js',
        'mode/yaml/yaml.min.js',
        'mode/python/python.min.js',
        'mode/go/go.min.js',
        'mode/ruby/ruby.min.js',
        'mode/markdown/markdown.min.js',
        'mode/diff/diff.min.js',
        'addon/hint/show-hint.min.js',
        'addon/hint/xml-hint.min.js',
        'addon/hint/html-hint.min.js',
        'addon/hint/css-hint.min.js',
        'addon/hint/javascript-hint.min.js',
        'addon/hint/sql-hint.min.js',
        // What show-hint falls back to for a language with no hints of its
        // own — shell, YAML, plain text: the words already in the field.
        'addon/hint/anyword-hint.min.js',
        'addon/edit/closetag.min.js',
        'addon/edit/closebrackets.min.js',
        'addon/edit/matchbrackets.min.js',
        'addon/edit/matchtags.min.js',
        'addon/fold/xml-fold.min.js',
        'addon/selection/active-line.min.js'
    ];

    var MODES = {
        html: 'htmlmixed',
        css: 'css',
        js: 'javascript',
        javascript: 'javascript',
        json: { name: 'javascript', json: true },
        php: 'application/x-httpd-php',
        bash: 'shell',
        shell: 'shell',
        sql: 'text/x-sql',
        yaml: 'yaml',
        ts: 'application/typescript',
        typescript: 'application/typescript',
        xml: 'xml',
        scss: 'text/x-scss',
        python: 'python',
        go: 'go',
        // Java comes from clike, which is already loaded for PHP.
        java: 'text/x-java',
        ruby: 'ruby',
        markdown: 'markdown',
        diff: 'diff',
        text: null
    };

    var SELECTOR = 'textarea.vela-code-input';
    var loading = null;

    function load() {
        if (window.CodeMirror && window.CodeMirror.showHint) return Promise.resolve();
        if (loading) return loading;

        STYLES.forEach(function (href) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = CDN + href;
            // Ahead of the admin's own stylesheet, so its dark theme wins.
            document.head.insertBefore(link, document.head.firstChild);
        });

        loading = SCRIPTS.reduce(function (chain, src) {
            return chain.then(function () {
                return new Promise(function (resolve, reject) {
                    var s = document.createElement('script');
                    s.src = CDN + src;
                    s.onload = resolve;
                    s.onerror = reject;
                    document.head.appendChild(s);
                });
            });
        }, Promise.resolve()).then(dropObsoleteTags);

        return loading;
    }

    // The suggestions are alphabetical, so `<di` offered `<dir` first and Enter
    // took it. Tags long gone from HTML are only in the way.
    function dropObsoleteTags() {
        var schema = window.CodeMirror.htmlSchema || {};
        ['acronym', 'applet', 'basefont', 'big', 'center', 'dir', 'font', 'frame', 'frameset',
            'isindex', 'noframes', 'strike', 'tt'].forEach(function (tag) { delete schema[tag]; });
    }

    function modeName(textarea) {
        var from = textarea.getAttribute('data-code-mode-from');
        var source = from ? document.querySelector(from) : null;
        var key = (source ? source.value : textarea.getAttribute('data-code-mode')) || 'text';
        return key.toLowerCase();
    }

    function modeFor(name) {
        return Object.prototype.hasOwnProperty.call(MODES, name) ? MODES[name] : null;
    }

    function fire(el, type) {
        el.dispatchEvent(new Event(type, { bubbles: true }));
    }

    // Letters, and the characters after which a suggestion makes sense: the
    // `<` that opens a tag, the space before an attribute, the `:` in CSS.
    function wantsHint(ed, name, text) {
        if (name === 'html') return /^[<\w\/"'=-]$/.test(text) || text === ' ';
        if (name === 'css') return /^[\w:-]$/.test(text);
        if (name === 'js' || name === 'javascript' || name === 'sql') return /^[\w.]$/.test(text);
        // The rest can only offer words already written, so wait for two
        // letters of one rather than listing the whole field at every key.
        if (!/^\w$/.test(text)) return false;
        var cur = ed.getCursor();
        return /\w{2,}$/.test(ed.getLine(cur.line).slice(0, cur.ch));
    }

    // What language a snippet is in, from how it starts — for a block just
    // added, whose Language is still whatever the block defaults to.
    function guessMode(code) {
        var s = code.replace(/^\s+/, '');
        if (!s) return null;
        if (/^<\?php/i.test(s)) return 'php';
        if (/^</.test(s)) return 'html';
        if (/^[\[{]/.test(s)) {
            try { JSON.parse(s); return 'json'; } catch (e) { /* not yet, or not JSON */ }
        }
        if (/^(#!|\$ |sudo |npm |composer |php artisan |git |cd |curl )/.test(s)) return 'bash';
        if (/^(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER)\s/i.test(s)) return 'sql';
        if (/^([.#]?[\w-]+(\s*[.#:>][\w-]+)*\s*\{|@media|:root)/.test(s)) return 'css';
        if (/^(const|let|var|function|import|export|document\.|window\.|console\.)\b/.test(s)) return 'js';
        return null;
    }

    function enhance(textarea) {
        if (textarea.velaCodeMirror || !window.CodeMirror) return;

        var name = modeName(textarea);
        var height = Math.max(textarea.offsetHeight || 0, (parseInt(textarea.getAttribute('rows'), 10) || 6) * 21 + 24);

        var cm = window.CodeMirror.fromTextArea(textarea, {
            mode: modeFor(name),
            lineNumbers: true,
            // A long line folds onto the next rather than running off to the
            // right, where it cannot be read without scrolling sideways.
            lineWrapping: true,
            indentUnit: name === 'html' ? 2 : 4,
            tabSize: 4,
            indentWithTabs: false,
            autoCloseTags: true,
            autoCloseBrackets: true,
            matchBrackets: true,
            matchTags: { bothTags: true },
            styleActiveLine: true,
            extraKeys: {
                'Ctrl-Space': 'autocomplete',
                'Cmd-Space': 'autocomplete',
                Tab: function (ed) {
                    if (ed.somethingSelected()) ed.indentSelection('add');
                    else ed.replaceSelection(Array(ed.getOption('indentUnit') + 1).join(' '), 'end');
                },
                'Shift-Tab': function (ed) { ed.indentSelection('subtract'); }
            },
            hintOptions: { completeSingle: false }
        });

        textarea.velaCodeMirror = cm;
        cm.getWrapperElement().classList.add('vela-code-editor');
        cm.setSize(null, height);

        cm.on('change', function (ed, change) {
            ed.save();
            // A value set from outside is not an edit: announcing it would
            // send page-editor.js's own write straight back to it as input.
            if (change.origin === 'setValue') return;
            fire(textarea, 'input');
        });
        cm.on('blur', function () { fire(textarea, 'change'); });

        var autoPick = function () {};

        cm.on('inputRead', function (ed, change) {
            autoPick();
            var current = modeName(textarea);
            if (ed.state.completionActive) return;
            if (change.origin === 'paste' || change.text.length > 1) return;
            if (wantsHint(ed, current, change.text[0])) ed.showHint({ completeSingle: false });
        });

        // Escape closes the list, not the dialog the editor is sitting in.
        cm.on('keydown', function (ed, e) {
            if (e.key === 'Escape' && ed.state.completionActive) e.stopPropagation();
        });

        var from = textarea.getAttribute('data-code-mode-from');
        var source = from ? document.querySelector(from) : null;
        if (source) {
            // Someone who has picked a language meant it; only an empty field
            // left on the default gets its language worked out from the code.
            var chosen = cm.getValue().trim() !== '';
            var picking = false;
            source.addEventListener('change', function () {
                if (!picking) chosen = true;
                cm.setOption('mode', modeFor(modeName(textarea)));
            });
            // Called on typing too, not only on `change`: CodeMirror reports the
            // keystroke before the change, and the `<` that makes it HTML
            // should already open the list of tags.
            autoPick = function () {
                if (chosen) return;
                var guess = guessMode(cm.getValue());
                if (!guess || guess === source.value) return;
                if (!source.querySelector('option[value="' + guess + '"]')) return;
                source.value = guess;
                picking = true;
                source.dispatchEvent(new Event('change', { bubbles: true }));
                picking = false;
            };
            cm.on('change', function (ed, change) {
                if (change.origin !== 'setValue') autoPick();
            });
        }

        // An editor drawn while hidden — in a closed <details>, a tab, a
        // dialog still fading in — measures nothing and shows a blank box
        // until it is told to look again.
        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (entries) {
                if (entries.some(function (en) { return en.isIntersecting; })) cm.refresh();
            }).observe(cm.getWrapperElement());
        }

        // Dragging the corner to make it taller.
        if ('ResizeObserver' in window) {
            new ResizeObserver(function () { cm.refresh(); }).observe(cm.getWrapperElement());
        }
    }

    function enhanceWithin(root) {
        var found = root.matches && root.matches(SELECTOR) ? [root] : [];
        if (root.querySelectorAll) found = found.concat(Array.prototype.slice.call(root.querySelectorAll(SELECTOR)));
        found = found.filter(function (t) { return !t.velaCodeMirror; });
        if (!found.length) return;

        load().then(function () {
            found.forEach(function (t) {
                // Still on the page: a dialog may have been closed meanwhile.
                if (document.body.contains(t)) enhance(t);
            });
        }).catch(function () { /* stay a plain textarea */ });
    }

    // `$('#html-content').val(html)` must reach the editor, or the markup
    // shown would stop matching the markup saved.
    if (window.jQuery) {
        var hooks = window.jQuery.valHooks;
        var previous = hooks.textarea;
        var hook = {
            set: function (el, value) {
                var cm = el.velaCodeMirror;
                var text = value == null ? '' : String(value);
                if (cm && cm.getValue() !== text) cm.setValue(text);
                return previous && previous.set ? previous.set(el, value) : undefined;
            }
        };
        // Only when there is one: jQuery calls `get` if the key exists at all.
        if (previous && previous.get) hook.get = previous.get;
        hooks.textarea = hook;
    }

    function start() {
        enhanceWithin(document.body);

        // The block dialog builds its form after the page has loaded.
        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                Array.prototype.forEach.call(m.addedNodes, function (node) {
                    if (node.nodeType === 1) enhanceWithin(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
