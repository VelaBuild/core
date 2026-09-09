/**
 * The little bar that appears at a part's corner in the section preview —
 * the thing you point at a card with, and drag it by.
 *
 * It is built by a script the editor writes into the preview's srcdoc, so it
 * runs in a document of its own with no test runner anywhere near it. This
 * lifts that script out of page-editor.js by the same trick the other file in
 * here uses, runs it against a real DOM, and asks the one question the browser
 * asks: does it survive being handed a part to restore?
 *
 * It did not. `focusPart` calls `hideMenu`, whose `menu` is created near the
 * end of the script; the line that restored the choice sat in the middle, so
 * `var menu` was hoisted and undefined, and restoring threw. Everything below
 * that line then never ran, and every later focusPart threw the same — the bar
 * stopped appearing on anything. From outside it looked like a drag that works
 * exactly once: the drop redraws the preview WITH a choice to restore, and
 * from then on nothing in the section can be pointed at or moved.
 *
 *     npm install jsdom && node tests/js/preview-part-bar.test.js
 */
const fs = require('fs');
const { JSDOM } = require('jsdom');

const src = fs.readFileSync(__dirname + '/../../public/js/page-editor.js', 'utf8');

let failures = 0;
function check(label, actual, expected) {
  const ok = String(actual) === String(expected);
  if (!ok) { failures++; console.log('FAIL  ' + label + '\n  expected: ' + expected + '\n  actual:   ' + actual); }
  else console.log('ok    ' + label);
}

/** The `var previewJs = …` concatenation, as source text. */
function previewScriptSource() {
  const start = src.indexOf('var previewJs =');
  if (start < 0) throw new Error('previewJs not found');
  const end = src.indexOf("'})();<\\/script>';", start);
  if (end < 0) throw new Error('end of previewJs not found');
  return src.slice(start, end + "'})();<\\/script>';".length);
}

/** And the helpers it splices in, which live in a var of their own. */
function previewHelpers() {
  const start = src.indexOf('var PREVIEW_PART_HELPERS =');
  const tail = "return node;}';";
  const end = src.indexOf(tail, start);
  if (start < 0 || end < 0) throw new Error('PREVIEW_PART_HELPERS not found');
  return src.slice(start, end + tail.length);
}

/**
 * Build the preview's script with a given choice to restore, then run it in a
 * document holding one row of three cards.
 */
function runPreview(picked, hold) {
  const asExpression = text => '(' + text.replace(/^var \w+ =/, '').replace(/;\s*$/, '') + ')';
  const PREVIEW_PART_HELPERS = eval(asExpression(previewHelpers()));
  const escHtml = s => String(s);
  const sortableScriptUrl = () => 'about:blank';
  const _htmlSelected = picked;
  const _htmlSelectedHold = hold;
  const built = eval(asExpression(previewScriptSource()));

  // Only the inline half: the <script src> is SortableJS, which is stubbed.
  const inline = built.slice(built.indexOf('<script>(function(){'));
  const code = inline.replace(/^<script>/, '').replace(/<\\?\/script>';?$/, '').replace(/<\/script>$/, '');

  const dom = new JSDOM(
    '<body><div data-vela-block><div class="row">' +
    '<div class="card" id="a"><h3>One</h3></div>' +
    '<div class="card" id="b"><h3>Two</h3></div>' +
    '<div class="card" id="c"><h3>Three</h3></div>' +
    '</div></div></body>',
    { runScripts: 'outside-only' }
  );

  const win = dom.window;
  win.Sortable = { create: () => ({ destroy() {} }) };
  // The preview talks to the editor through the frame's parent.
  win.parent = { postMessage() {} };

  let threw = null;
  try { win.eval(code); } catch (e) { threw = e; }

  const bar = Array.from(win.document.querySelectorAll('[data-vela-ui]'))
    .find(el => el.querySelector('.vela-grip'));

  return {
    threw,
    win,
    bar,
    barShown: !!bar && bar.style.display !== 'none',
    on: bar && bar.parentElement ? bar.parentElement.id : null,
    pinned: (win.document.querySelector('[data-vela-pinned]') || {}).id || null,
    menu: !!win.document.querySelector('.vela-menu'),
  };
}

// --- opening a section with nothing chosen ---
let r = runPreview(null, true);
check('a fresh preview runs without throwing', r.threw ? r.threw.message : 'nothing thrown', 'nothing thrown');
check('and builds the picture menu it will need', r.menu, true);
check('with the bar waiting, hidden', r.barShown, false);

// --- the state a drop leaves behind: a part to restore ---
r = runPreview('0/1', false);
check('restoring a choice does not throw', r.threw ? r.threw.message : 'nothing thrown', 'nothing thrown');
// This is the assertion that fails without the fix: the script died at the
// restore, so nothing below it — the menu among it — was ever created.
check('the rest of the script still runs', r.menu, true);
check('the bar is on the part that was handed back', r.on, 'b');
check('and it is visible', r.barShown, true);

// --- shown, but not held, so the pointer can move on to the next card ---
check('a move does not hold the part it moved', r.pinned, 'null');

// --- a redraw from the panel holds it, because the pointer is out there ---
r = runPreview('0/2', true);
check('a panel redraw holds its part', r.pinned, 'c');
check('and still builds the menu', r.menu, true);

// --- hovering another card after that still answers ---
r = runPreview('0/1', false);
const other = r.win.document.getElementById('c');
other.dispatchEvent(new r.win.MouseEvent('mouseover', { bubbles: true }));
check('the bar follows the pointer to another card', r.bar.parentElement.id, 'c');

console.log(failures ? '\n' + failures + ' failed' : '\nall passed');
process.exit(failures ? 1 : 0);
