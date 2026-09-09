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
    '<div class="card" id="c"><h3>Three</h3>' +
    '<img id="pic" data-vela-field="f1" data-vela-field-kind="image" src="/a.jpg">' +
    '</div>' +
    '</div><div class="row" id="row2">' +
    '<div class="card" id="d"><h3>Four</h3></div>' +
    '<div class="card" id="e"><h3>Five</h3></div>' +
    '</div></div></body>',
    { runScripts: 'outside-only' }
  );

  const win = dom.window;
  // Enough of SortableJS for the script to wire itself up: what is under test
  // is which box is given a live handle and what a finished drag reports.
  const made = [];
  win.Sortable = {
    create(el, opts) {
      const instance = { el, opts, option(k, v) { opts[k] = v; }, destroy() {} };
      made.push(instance);
      return instance;
    },
  };
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
    made,
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

// --- a card can be dragged out of its own box, not only within it ---------
//
// One list was bound at a time and it was the only Sortable in the document,
// so there was nowhere else for a drop to land: a card could be reordered
// among its siblings and could not leave the row. Every box now accepts;
// only the box the pointer is in can begin a drag.
r = runPreview(null, true);
const doc = r.win.document;
const row = doc.querySelector('.row');
const outer = doc.querySelector('[data-vela-block]');

check('every box that holds parts can receive a drop',
  r.made.filter(m => m.el === row).length + ',' + r.made.filter(m => m.el === outer).length, '1,1');
check('they share one group, so a part can travel between them',
  r.made.every(m => m.opts.group && m.opts.group.name === 'vela-parts'), true);
check('and none of them can start a drag until it is pointed at',
  r.made.every(m => m.opts.handle === '.vela-not-a-handle'), true);

doc.getElementById('b').dispatchEvent(new r.win.MouseEvent('mouseover', { bubbles: true }));
const rowSortable = r.made.find(m => m.el === row);
const outerSortable = r.made.find(m => m.el === outer);
check('the box under the pointer gets the live handle',
  rowSortable.opts.handle, '.vela-grip,[data-vela-drag-self]');
check('and the others stay receive-only', outerSortable.opts.handle, '.vela-not-a-handle');

// A finished drag, reported: card #1 of the row dropped into the box around
// it, at the end. Both ends of the move have to be named, or the editor has
// no way to know it left the row it started in.
let sent = null;
r.win.parent.postMessage = message => { sent = message; };
rowSortable.opts.onStart({ from: row, item: doc.getElementById('b') });
outer.appendChild(doc.getElementById('b'));
rowSortable.opts.onEnd({ from: row, to: outer, item: doc.getElementById('b') });
check('a drop into another box names both ends',
  JSON.stringify(sent && sent.velaMove),
  JSON.stringify({ from: { container: '0', index: 1 }, to: { container: '', index: 2 } }));

// And the ordinary case still reports as it did.
r = runPreview(null, true);
const doc2 = r.win.document, row2 = doc2.querySelector('.row');
doc2.getElementById('a').dispatchEvent(new r.win.MouseEvent('mouseover', { bubbles: true }));
const rs2 = r.made.find(m => m.el === row2);
let sent2 = null;
r.win.parent.postMessage = message => { sent2 = message; };
rs2.opts.onStart({ from: row2, item: doc2.getElementById('a') });
row2.appendChild(doc2.getElementById('a'));
rs2.opts.onEnd({ from: row2, to: row2, item: doc2.getElementById('a') });
check('a reorder inside one box still reports one box',
  JSON.stringify(sent2 && sent2.velaMove),
  JSON.stringify({ from: { container: '0', index: 0 }, to: { container: '0', index: 2 } }));

// --- and the other half of the same gesture: applying the move ------------
//
// The editor holds the section as a document of its own and the preview only
// describes what happened to it. Both ends arrive as paths measured before
// anything moved, so both are resolved before anything moves here either.
function extract(name) {
  const start = src.indexOf('function ' + name + '(');
  if (start < 0) throw new Error('not found: ' + name);
  let i = src.indexOf('{', start), depth = 0;
  for (let j = i; j < src.length; j++) {
    if (src[j] === '{') depth++;
    else if (src[j] === '}') { depth--; if (depth === 0) return src.slice(start, j + 1); }
  }
  throw new Error('unbalanced: ' + name);
}
eval(extract('nodeAtPath') + '\n' + extract('moveImportedPart'));

function section() {
  return new JSDOM(
    '<body><div data-vela-block="b1">' +
    '<div class="row" id="top"><div id="a">A</div><div id="b">B</div></div>' +
    '<div class="row" id="bottom"><div id="c">C</div></div>' +
    '</div></body>'
  ).window.document;
}
const ids = el => Array.prototype.map.call(el.children, c => c.id).join(',');

let _htmlDoc = section();
let out = moveImportedPart('0', 0, '0', 1);
check('a part moves to the right inside its own box', ids(_htmlDoc.getElementById('top')), 'b,a');
check('and the moved element comes back', out && out.id, 'a');

_htmlDoc = section();
moveImportedPart('0', 1, '0', 0);
check('and to the left', ids(_htmlDoc.getElementById('top')), 'b,a');

_htmlDoc = section();
out = moveImportedPart('0', 1, '1', 0);
check('a part can leave its box for another one', ids(_htmlDoc.getElementById('top')), 'a');
check('landing where the drop said', ids(_htmlDoc.getElementById('bottom')), 'b,c');
check('with its id, and everything keyed by it, along for the ride', out && out.id, 'b');

_htmlDoc = section();
check('a box cannot be dropped inside itself', moveImportedPart('', 0, '0', 0), 'null');
check('and nothing moved', ids(_htmlDoc.querySelector('[data-vela-block]')), 'top,bottom');

// --- a picture answers a click the way everything else does ---------------
//
// Clicking one used to open the media library on the spot, so a click meant to
// SELECT a picture — or to start dragging it — was answered by a dialog nobody
// asked for. Reported as "I don't like that clicking a picture opens a modal".
r = runPreview(null, true);
const pic = r.win.document.getElementById('pic');
let posted = [];
r.win.parent.postMessage = message => posted.push(message);

pic.dispatchEvent(new r.win.MouseEvent('click', { bubbles: true, cancelable: true }));
check('clicking a picture opens nothing',
  posted.filter(m => m.velaImage).length, 0);
check('it chooses it, like any other part',
  JSON.stringify(posted.filter(m => 'velaSelect' in m).map(m => m.velaSelect)), JSON.stringify(['0/2/1']));

posted = [];
pic.dispatchEvent(new r.win.MouseEvent('dblclick', { bubbles: true, cancelable: true }));
check('and double-clicking still goes straight to the media library',
  JSON.stringify(posted.filter(m => m.velaImage).map(m => m.velaImage.field)), JSON.stringify(['f1']));

// The bar's picture button has two jobs and says which one it is offering.
const barButton = () => r.bar.querySelector('.vela-picture').title;
pic.dispatchEvent(new r.win.MouseEvent('mouseover', { bubbles: true }));
check('on a picture the button offers to change it', barButton(), 'Change this picture');
// A click, not a hover: the picture is HELD after being clicked, and a held
// part is exactly what stops the bar wandering off while you reach for it.
r.win.document.getElementById('a').dispatchEvent(new r.win.MouseEvent('click', { bubbles: true, cancelable: true }));
check('on anything else it offers to put one in', barButton(), 'Put a picture in this');

posted = [];
pic.dispatchEvent(new r.win.MouseEvent('click', { bubbles: true, cancelable: true }));
r.bar.querySelector('.vela-picture').dispatchEvent(new r.win.MouseEvent('click', { bubbles: true, cancelable: true }));
check('and pressing it on a picture asks for that picture',
  JSON.stringify(posted.filter(m => m.velaImage).map(m => m.velaImage.field)), JSON.stringify(['f1']));

// --- a card must not sink into another card ------------------------------
//
// Every box that holds parts can receive, and a card is a box: dropping one
// card ON another put it INSIDE it, and the next drag buried it one level
// further. Measured on a real section — div.fb-feature-card inside
// div.fb-feature-card — and reported as cards sinking as you drag them about.
//
// What may receive a part is now decided the way the editor already decides
// what a row of cards is: the same tag, and a class they share.
r = runPreview(null, true);
const takes = (boxId, itemId) => {
  const box = r.win.document.getElementById(boxId);
  const put = box.__velaSortable ? box.__velaSortable.opts.group.put : null;
  if (typeof put !== 'function') return 'box ' + boxId + ' has no rule';
  return put({ el: box }, null, r.win.document.getElementById(itemId));
};

check('a card is refused by another card', takes('a', 'b'), false);
check('and taken by the row across the section', takes('row2', 'b'), true);

// The row you just emptied has to be fillable again, and a picture is a
// picture wherever it came from.
const emptied = r.win.document.getElementById('row2');
while (emptied.firstElementChild) emptied.removeChild(emptied.firstElementChild);
check('a box holding nothing takes anything', takes('row2', 'b'), true);
check('a card still refuses a picture', takes('a', 'pic'), false);

// --- Backspace and Delete take a part out ---------------------------------
//
// The bar's × has always done this; the keys are how anybody would try it
// first. Same message, so there is one action and two ways to reach it.
r = runPreview(null, true);
const press = (key, opts) => {
  const event = new r.win.KeyboardEvent('keydown', Object.assign({ key, bubbles: true, cancelable: true }, opts || {}));
  (opts && opts.on ? opts.on : r.win.document).dispatchEvent(event);
  return event;
};
posted = [];
r.win.parent.postMessage = message => posted.push(message);

r.win.document.getElementById('b').dispatchEvent(new r.win.MouseEvent('click', { bubbles: true, cancelable: true }));
posted = [];
let pressed = press('Backspace');
check('backspace takes out the part that is held',
  JSON.stringify(posted.filter(m => 'velaPick' in m).map(m => m.velaPick)), JSON.stringify(['0/1']));
check('and the browser does not go back a page instead', pressed.defaultPrevented, true);

posted = [];
press('Delete');
check('delete does the same', JSON.stringify(posted.filter(m => 'velaPick' in m).map(m => m.velaPick)), JSON.stringify(['0/1']));

// Wording being typed owns both keys — a heading shortened one character at a
// time would otherwise disappear on the first press.
const heading = r.win.document.querySelector('#c h3');
// jsdom does not implement contentEditable, so the property the guard reads is
// set here rather than inferred from the attribute.
Object.defineProperty(heading, 'isContentEditable', { value: true, configurable: true });
Object.defineProperty(r.win.document, 'activeElement', { get: () => heading, configurable: true });
posted = [];
pressed = press('Backspace');
check('but not while wording is being typed', posted.filter(m => 'velaPick' in m).length, 0);
check('and the keystroke is left alone there', pressed.defaultPrevented, false);

console.log(failures ? '\n' + failures + ' failed' : '\nall passed');
process.exit(failures ? 1 : 0);
