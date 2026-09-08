/**
 * The link and picture-size controls the page builder puts on a written
 * section, exercised against a real DOM.
 *
 * PHP cannot reach these — they run in the browser, in the editor — and the
 * repository has no JavaScript test runner, so this one stands on its own:
 *
 *     npm install jsdom && node tests/js/page-editor-links.test.js
 *
 * The functions are lifted out of page-editor.js by name rather than imported,
 * because the file is one IIFE that expects jQuery and a page around it.
 */
const fs = require('fs');
const { JSDOM } = require('jsdom');

const src = fs.readFileSync(__dirname + '/../../public/js/page-editor.js', 'utf8');

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

const names = ['couldCarryALink', 'linkAnchor', 'applyLink', 'imageWidth', 'applyImageWidth',
  'upgradeImportedBlock', 'hashString', 'wrapLooseText', 'partWorthEditingAbove', 'partName',
  'insertPictureAt', 'importedPathOf', 'nodeAtPath', 'nextImportedId',
  'sanitizeDesign', 'designCss', 'safeCssValue', 'throughLinkWrap'];
// Vars rather than functions, so they are lifted by name too.
const vars = [/var COLUMN_CLASS = [^;]+;/, /var LOOSE_TEXT_SKIP = \{[^}]*\};/,
  /var VOID_TAGS = [^\n]+/, /var DESIGN_COLOUR = [^\n]+/, /var DESIGN_LENGTH = [^\n]+/,
  /var DESIGN_IMAGE = [^\n]+/, /var DESIGN_FONTS = \[[\s\S]*?\];/,
  /var PART_WEIGHTS = [^\n]+/, /var PART_SIZES = [^\n]+/, /var PART_PLACES = [^\n]+/,
  /var PART_HEIGHTS = [^\n]+/, /var DESIGN_HEIGHT = [^\n]+/];
eval(vars.map(function (pattern) { return pattern.exec(src)[0]; }).join('\n')
  + '\n' + names.map(extract).join('\n'));

let failures = 0;
function check(label, actual, expected) {
  const ok = String(actual) === String(expected);
  if (!ok) { failures++; console.log('FAIL  ' + label + '\n  expected: ' + expected + '\n  actual:   ' + actual); }
  else console.log('ok    ' + label);
}

function docFrom(html) {
  return new JSDOM('<body>' + html + '</body>').window.document;
}

// --- a heading that is not a link gets wrapped ---
let doc = docFrom('<div class="grid"><div class="card"><h3>Fast</h3></div></div>');
let card = doc.querySelector('.card');
applyLink(card, '/features', false);
check('card is wrapped in a link',
  doc.querySelector('.grid').innerHTML,
  '<a data-vela-link-wrap="1" style="display:contents;color:inherit;text-decoration:inherit;" href="/features"><div class="card"><h3>Fast</h3></div></a>');

// --- new tab adds target and rel ---
applyLink(card, '/features', true);
check('new tab sets target', doc.querySelector('a').getAttribute('target'), '_blank');
check('new tab sets rel', doc.querySelector('a').getAttribute('rel'), 'noopener noreferrer');
applyLink(card, '/features', false);
check('same tab removes target', doc.querySelector('a').getAttribute('target'), 'null');
check('same tab removes rel', doc.querySelector('a').getAttribute('rel'), 'null');

// --- clearing the href unwraps, leaving the markup as it was ---
applyLink(card, '', false);
check('clearing the link unwraps it', doc.querySelector('.grid').innerHTML, '<div class="card"><h3>Fast</h3></div>');

// --- an element that already IS a link is not wrapped ---
doc = docFrom('<a class="btn" href="/old">Start</a>');
let anchor = doc.querySelector('a');
applyLink(anchor, '/new', true);
check('an existing link is edited in place', doc.body.innerHTML, '<a class="btn" href="/new" target="_blank" rel="noopener noreferrer">Start</a>');
applyLink(anchor, '', false);
check('clearing an existing link leaves the element', doc.body.innerHTML, '<a class="btn">Start</a>');

// --- nothing inside a link may become one ---
doc = docFrom('<a href="/plans"><strong>See the plans</strong></a>');
applyLink(doc.querySelector('strong'), '/other', false);
check('no anchor is nested inside another', doc.body.innerHTML, '<a href="/plans"><strong>See the plans</strong></a>');

// --- couldCarryALink ---
doc = docFrom('<div class="grid"><div class="card" data-vela-card="c1-1"><h3>A</h3></div>'
  + '<div class="card" data-vela-card="c1-2"><a href="/b">B</a></div></div>'
  + '<ul><li>One</li></ul><a href="/x"><span>inside</span></a><img src="/a.png">');
check('a card can carry a link', couldCarryALink(doc.querySelector('[data-vela-card="c1-1"]'), []), 'true');
check('a card holding a link cannot', couldCarryALink(doc.querySelector('[data-vela-card="c1-2"]'), []), 'false');
check('a bullet can carry a link', couldCarryALink(doc.querySelector('li'), ['text']), 'true');
check('wording inside a link cannot', couldCarryALink(doc.querySelector('span'), ['text']), 'false');
check('a picture can carry a link', couldCarryALink(doc.querySelector('img'), ['image']), 'true');
check('a bare container cannot', couldCarryALink(doc.querySelector('.grid'), []), 'false');

// --- image width ---
doc = docFrom('<img src="/a.png"><img src="/b.png" style="border-radius:8px;width:40%;height:auto">');
let [plain, sized] = doc.querySelectorAll('img');
check('an unsized picture reads as full width', imageWidth(plain), '100');
check('a sized picture reads its width', imageWidth(sized), '40');

applyImageWidth(plain, 60);
check('the width is written as a share', plain.getAttribute('style'), 'width:60%;height:auto');
applyImageWidth(plain, 100);
check('full width removes the setting', plain.getAttribute('style'), 'null');
applyImageWidth(sized, 75);
check('other styling on the picture survives', sized.getAttribute('style'), 'border-radius:8px;width:75%;height:auto');
applyImageWidth(sized, 100);
check('full width keeps the rest', sized.getAttribute('style'), 'border-radius:8px');
applyImageWidth(sized, 5);
check('a width outside the range is ignored', sized.getAttribute('style'), 'border-radius:8px');

// --- upgradeImportedBlock: what counts as a row of cards ---
// The editor keeps its own copy of SectionImporter::markGrids, for a section
// pasted in or copied before the marks existed, and it had the same fault: it
// wanted the class LISTS to match, so a base class plus a modifier per card —
// the commonest shape there is — was not a set. The user found it the hard
// way: a link meant for a feature card landed on the <img> inside it.
doc = docFrom('<div data-vela-block="b1"><div class="row">'
  + '<div class="feature-card card-chat"><h3>Chat</h3></div>'
  + '<div class="feature-card card-task"><h3>Tasks</h3></div>'
  + '</div></div>');
upgradeImportedBlock(doc);
check('cards differing by a modifier are a set', doc.querySelectorAll('[data-vela-card]').length, '2');
check('and their row is the grid', doc.querySelector('.row').getAttribute('data-vela-grid-count'), '2');
check('so a whole card can carry a link',
  couldCarryALink(doc.querySelector('.card-chat'), []), 'true');

doc = docFrom('<div data-vela-block="b2"><div class="hero-inner">'
  + '<div class="hero-words"><h1>Hi</h1></div><div class="hero-shot"><img src="/a.png"></div>'
  + '</div></div>');
upgradeImportedBlock(doc);
check('children sharing no class are not a set', doc.querySelectorAll('[data-vela-card]').length, '0');

doc = docFrom('<div data-vela-block="b3"><ul><li>One</li><li>Two</li></ul></div>');
upgradeImportedBlock(doc);
check('children with no classes at all still are', doc.querySelectorAll('[data-vela-card]').length, '2');

// --- the way out of a decorative part ---
// A section is full of shapes that hold nothing — a scrim, a grid line, a dot
// — and they lie on top of what somebody meant to point at, so they are easy
// to hit. "Pick something inside it" was a dead end: there is nothing inside.
doc = docFrom('<div data-vela-block="b4"><div class="card" data-vela-card="c1-1">'
  + '<div class="scrim"></div><h3 data-vela-field="f1" data-vela-field-kind="text">Chat</h3>'
  + '</div></div>');
let above = partWorthEditingAbove(doc, doc.querySelector('.scrim'));
check('a decorative shape offers the thing it sits in', above && above.label, 'Card');
check('and addresses it the way the breadcrumb does', above && above.path, '0');

doc = docFrom('<div data-vela-block="b5"><div class="deco"><span class="dot"></span></div></div>');
check('with nothing above worth editing, nothing is offered',
  partWorthEditingAbove(doc, doc.querySelector('.dot')), 'null');

// --- putting a picture into a part ---
// The only route into a card with no picture used to be typing an <img> into
// the HTML by hand — and one typed there renders but cannot be swapped or
// linked, because the marks the form reads are added at import and never again.
function withDoc(html) {
  const d = docFrom('<div data-vela-block="b1">' + html + '</div>');
  _htmlDoc = d;                       // what insertPictureAt() works on
  return d;
}
let _htmlDoc = null;

doc = withDoc('<div class="card" data-vela-field="f1" data-vela-field-kind="linkable">'
  + '<h3 data-vela-field="f2" data-vela-field-kind="text">Tasks</h3></div>');
let placed = insertPictureAt('0', { url: '/images/a.png', alt: 'A picture' });
let img = doc.querySelector('img');
check('a picture goes inside a part that can hold one', img && img.parentElement.className, 'card');
check('it is the last thing in it', img && img.previousElementSibling.tagName, 'H3');
check('it carries the marks the form reads', img && img.getAttribute('data-vela-field-kind'), 'image linkable');
check('with an id nothing else has', img && img.getAttribute('data-vela-field'), 'f3');
check('and the alt text that came with it', img && img.getAttribute('alt'), 'A picture');
check('it does not overflow what it was put in', img && img.getAttribute('style'), 'max-width:100%;height:auto');
check('the path returned finds it again', nodeAtPath(doc, placed), img);

// A heading cannot hold a picture: it would land between the words.
doc = withDoc('<div class="card"><h3 data-vela-field="f1" data-vela-field-kind="text">Tasks</h3>'
  + '<p data-vela-field="f2" data-vela-field-kind="text">Assign them.</p></div>');
placed = insertPictureAt('0/0', { url: '/images/b.png' });
img = doc.querySelector('img');
check('a picture goes after what cannot hold it', img && img.previousElementSibling.tagName, 'H3');
check('and stays inside the card', img && img.parentElement.className, 'card');
check('the path returned finds that one too', nodeAtPath(doc, placed), img);

// A part with a link on it is wrapped in an anchor, and the anchor is what the
// pointer resolves to. A picture put in THERE is a sibling of the card: inside
// the link, outside the box it was meant for.
doc = withDoc('<a data-vela-link-wrap="1" href="/x" style="display:contents">'
  + '<div class="card"><h3 data-vela-field="f1" data-vela-field-kind="text">Tasks</h3></div></a>');
placed = insertPictureAt('0', { url: '/images/d.png' });
img = doc.querySelector('img');
check('a picture goes past the link wrapper into the card', img && img.parentElement.className, 'card');
check('and the path returned is the one it is really at', nodeAtPath(doc, placed), img);

doc = withDoc('<div class="card"><h3>Tasks</h3></div>');
check('nothing is placed without a picture to place', insertPictureAt('0', { alt: 'no url' }), 'null');
check('nor for a part that is not there', insertPictureAt('7/7', { url: '/images/c.png' }), 'null');

// --- a picture behind a part ---
// "Can a card have a picture as its background?" The colour field cannot say
// it: it takes a colour and refuses everything else, because the value is
// written straight into a stylesheet.
let css = designCss('b1', sanitizeDesign({
  parts: { p1: { bgImage: '/images/team.png' } },
}), []);
check('the picture is drawn behind the part',
  /\[data-vela-part="p1"\]\{[^}]*background-image:url\("\/images\/team\.png"\)/.test(css), 'true');
check('and fills it rather than tiling',
  /background-size:cover !important;background-position:center !important;background-repeat:no-repeat/.test(css), 'true');

css = designCss('b1', sanitizeDesign({
  parts: { p1: { bgImage: '/images/team.png', bgFit: 'contain', darken: '40%' } },
}), []);
check('darkening goes in front of it, in the same property',
  /background-image:linear-gradient\(rgba\(0,0,0,0\.4\),rgba\(0,0,0,0\.4\)\),url\("\/images\/team\.png"\)/.test(css), 'true');
check('and how it fits is honoured', /background-size:contain/.test(css), 'true');

// The value is written into a stylesheet, so anything that could close the
// url() it sits in has to be refused rather than escaped.
[
  ['/img.png") ;} body{display:none', 'a url that closes the declaration'],
  ['javascript:alert(1)', 'a script url'],
  ['/img.png\'', 'a url carrying a quote'],
  ['../secret', 'a relative path that is not one'],
].forEach(function (pair) {
  const cleaned = sanitizeDesign({ parts: { p1: { bgImage: pair[0] } } });
  check(pair[1] + ' is refused', (cleaned.parts && cleaned.parts.p1) ? 'kept' : 'dropped', 'dropped');
});

check('a plain https address is kept',
  sanitizeDesign({ parts: { p1: { bgImage: 'https://cdn.example.com/a.jpg' } } }).parts.p1.bgImage,
  'https://cdn.example.com/a.jpg');
check('a darkening the controls do not offer is refused',
  (sanitizeDesign({ parts: { p1: { bgImage: '/a.png', darken: '999' } } }).parts.p1.darken) === undefined, 'true');

// --- where the content of a taller box sits ---
// The card this was asked about carries `justify-content:flex-end` in the
// design's own stylesheet, so its heading sat at the bottom and looked
// deliberate — until a picture was added and the lot slid down together.
css = designCss('b1', sanitizeDesign({ parts: { p1: { place: 'top' } } }), []);
check('the content can be sent to the top',
  /\[data-vela-part="p1"\]\{[^}]*justify-content:flex-start !important;align-content:flex-start/.test(css), 'true');
check('both properties, since the box may be flex or grid',
  (css.match(/align-content/g) || []).length, '1');

css = designCss('b1', sanitizeDesign({ parts: { p1: { place: 'spread' } } }), []);
check('spreading it out is space-between', /justify-content:space-between/.test(css), 'true');

check('a placement the control does not offer is refused',
  (sanitizeDesign({ parts: { p1: { place: 'flex-end;color:red' } } }).parts) === undefined, 'true');

// --- how tall a card is ---
// There was no way to say it at all: the design's own min-height decided, and
// a card given a picture grew while the one beside it stayed as it was.
css = designCss('b1', sanitizeDesign({ parts: { p1: { height: '400px' } } }), []);
check('the height is written as a floor, not a fixed size',
  /\[data-vela-part="p1"\]\{[^}]*min-height:400px !important/.test(css), 'true');
check('and never as height itself', /[^-]height:400px/.test(css.replace(/min-height/g, 'X')), 'false');

css = designCss('b1', sanitizeDesign({ parts: { p1: { height: 'auto' } } }), []);
check('auto gives the floor up', /min-height:auto !important/.test(css), 'true');

check('a height the control does not offer is refused',
  (sanitizeDesign({ parts: { p1: { height: '400px;position:fixed' } } }).parts) === undefined, 'true');

console.log(failures ? '\n' + failures + ' FAILED' : '\nall passed');
process.exit(failures ? 1 : 0);
