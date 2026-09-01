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

const names = ['couldCarryALink', 'linkAnchor', 'applyLink', 'imageWidth', 'applyImageWidth'];
eval(names.map(extract).join('\n'));

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

console.log(failures ? '\n' + failures + ' FAILED' : '\nall passed');
process.exit(failures ? 1 : 0);
