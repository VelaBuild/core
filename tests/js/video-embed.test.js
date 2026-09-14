/**
 * The page editor's video preview, held to the same cases as the page.
 *
 * VideoEmbed.php draws the player on the site; page-editor.js previews it as
 * the form is changed. Both run tests/fixtures/video-embeds.json, so a case
 * added there binds both sides at once.
 *
 *     node tests/js/video-embed.test.js
 */
const fs = require('fs');

const src = fs.readFileSync(__dirname + '/../../public/js/page-editor.js', 'utf8');
const table = JSON.parse(fs.readFileSync(__dirname + '/../fixtures/video-embeds.json', 'utf8'));

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

function lift(varName) {
  const m = new RegExp('var ' + varName + ' = [\\s\\S]*?;\\n').exec(src);
  if (!m) throw new Error('var not found: ' + varName);
  return m[0];
}

eval(['VIDEO_RATIOS', 'VIDEO_MAX_WIDTH', 'VIDEO_DEFAULTS', 'THAI_DIGITS'].map(lift).join('\n') + '\n' +
  ['videoSeconds', 'parseVideoLink', 'videoFlag', 'videoSettings', 'videoQuery', 'videoEmbedUrl', 'videoClock']
    .map(extract).join('\n'));

let failures = 0;
function check(label, actual, expected) {
  const ok = String(actual) === String(expected);
  if (!ok) { failures++; console.log('FAIL  ' + label + '\n  expected: ' + expected + '\n  actual:   ' + actual); }
  else console.log('ok    ' + label);
}

table.cases.forEach(c => check(c.name, videoEmbedUrl(c.url, c.settings), c.embed));
table.times.forEach(([typed, seconds]) => check('time "' + typed + '"', videoSeconds(typed), seconds));

// The field shows a time the way it was read, so a link's own time can be
// written into it and read back the same.
check('seconds are written back as a clock', videoClock(90), '1:30');
check('with hours when there are any', videoClock(3723), '1:02:03');
check('and read again to the same number', videoSeconds(videoClock(3723)), 3723);

console.log(failures ? '\n' + failures + ' failed' : '\nall passed');
process.exit(failures ? 1 : 0);
