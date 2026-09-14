/**
 * Undo inside a section's dialog, for styling given to one part.
 *
 * A part's styling — a colour, a size, a picture put behind a card — is held
 * in memory (`_htmlPartStyles`) as well as written into the section's markup,
 * and the next redraw writes the markup FROM the memory. Undo restored the
 * markup and left the memory alone, so the redraw painted the undone styling
 * straight back: a background picture could not be taken off with Ctrl+Z.
 *
 *     npm install jsdom && node tests/js/section-undo.test.js
 */
const fs = require('fs');
const { JSDOM } = require('jsdom');

const src = fs.readFileSync(__dirname + '/../../public/js/page-editor.js', 'utf8');
global.DOMParser = new JSDOM('').window.DOMParser;

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

let failures = 0;
function check(label, actual, expected) {
  const ok = String(actual) === String(expected);
  if (!ok) { failures++; console.log('FAIL  ' + label + '\n  expected: ' + expected + '\n  actual:   ' + actual); }
  else console.log('ok    ' + label);
}

// The editor's state, as page-editor.js holds it.
let _htmlDoc = null, _htmlHidden = [], _htmlPartStyles = {}, _restoring = false;
let redrawnFromDoc = null;
function redrawImportedEditor(fromDoc) { redrawnFromDoc = fromDoc; }
function updateHistoryButtons() {}

eval(/var EDITOR_FURNITURE = [^\n]+/.exec(src)[0] + '\n' +
  ['stripEditorFurniture', 'parseBlockHtml', 'serializeBlockHtml', 'readDesign', 'blockState', 'applyBlockState']
    .map(extract).join('\n'));

const section = design => '<div data-vela-block="b1"' +
  (design ? " data-vela-design='" + JSON.stringify(design) + "'" : '') +
  '><div class="card" data-vela-part="p1"><h3>Scheduling</h3></div></div>';

// Before: a card with nothing behind it. After: a picture put behind it.
const before = JSON.stringify({ html: section(null), hidden: [] });
_htmlDoc = parseBlockHtml(section({ parts: { p1: { bgImage: '/storage/3/shot.png', darken: '40%' } } }));
_htmlPartStyles = { p1: { bgImage: '/storage/3/shot.png', darken: '40%' } };

applyBlockState(before);
check('undo takes the picture off the part', JSON.stringify(_htmlPartStyles), '{}');
check('and the redraw is drawn from the restored markup', redrawnFromDoc, true);

// And redo puts it back, styling and all.
const after = JSON.stringify({ html: section({ parts: { p1: { bgImage: '/storage/3/shot.png', color: '#fff' } } }), hidden: [] });
applyBlockState(after);
check('redo brings the picture back', _htmlPartStyles.p1 && _htmlPartStyles.p1.bgImage, '/storage/3/shot.png');
check('with the rest of that part\'s styling', _htmlPartStyles.p1 && _htmlPartStyles.p1.color, '#fff');

// A copy, not the parsed object: the panel edits this in place, and editing
// it must not reach back into the state the history is holding.
_htmlPartStyles.p1.color = '#000';
check('what is restored is a copy the panel can change freely',
  readDesign(_htmlDoc.querySelector('[data-vela-block]')).parts.p1.color, '#fff');

console.log(failures ? '\n' + failures + ' failed' : '\nall passed');
process.exit(failures ? 1 : 0);
