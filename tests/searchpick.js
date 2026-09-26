// The SearchPick matcher, run for real.
//
// assets/app.js is a browser file with no build step and no modules, so it is
// read and evaluated here against a stub document - only the parts app.js
// touches while it loads. Nothing in this file talks to the database or the
// screen; it answers one question: given a list of names and something typed,
// which rows come back and in what order.
//
// Run from tests/run.php, which prints the result with the PHP assertions and
// skips the whole thing when node is not installed. Prints one JSON object.
const fs = require('fs');
const path = require('path');

const stubEl = { addEventListener() {}, classList: { add() {}, remove() {}, toggle() {} },
                 style: {}, dataset: {}, querySelectorAll: () => [], appendChild() {} };
global.document = {
  addEventListener() {}, getElementById: () => null, querySelectorAll: () => [],
  querySelector: () => null, createElement: () => Object.assign({}, stubEl), body: stubEl,
};
global.window = global;
global.localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
global.navigator = { userAgent: 'node' };

eval(fs.readFileSync(path.join(__dirname, '..', 'assets', 'app.js'), 'utf8'));

// The real thing the owner reported: an item whose name has the useful words
// scattered through it, sitting in a list of hundreds.
const NAMES = [
  'iVOOMi DREAM60 22-Inch Monitor',
  'Dell 24 Inch Monitor E2422H',
  'Monitor Stand Adjustable',
  'HP 22 Inch LED Monitor',
  'Logitech Wireless Mouse M221',
  'iVOOMi Keyboard Combo',
];

// what init() does to each row, without a DOM: the text it searches on
const hay = (n) => n.toLowerCase().replace(/\s+/g, ' ');

function search(query) {
  const qy = query.trim().toLowerCase().replace(/\s+/g, ' ');
  const toks = qy === '' ? [] : qy.split(' ');
  const rows = NAMES
    .map((n, i) => ({ n, i, r: qy === '' ? 0 : SearchPick.rank(hay(n), qy, toks),
                      p: hay(n).indexOf(toks[0] || ''), len: n.length }))
    .filter((x) => x.r >= 0);
  // an empty box is the plain list in its own order - nothing to rank
  if (qy !== '') rows.sort((a, b) => a.r - b.r || a.p - b.p || a.len - b.len || a.i - b.i);
  return rows.map((x) => x.n);
}

// a party list: the name is not the only way people look one up
const PARTIES = [
  { name: 'Varish Madlani', mob: '9876543210' },
  { name: 'Vishal Electronics', mob: '9123456780' },
];
function searchParty(query) {
  const qy = query.trim().toLowerCase().replace(/\s+/g, ' ');
  const toks = qy === '' ? [] : qy.split(' ');
  return PARTIES
    .map((p) => ({ p, r: SearchPick.rank((p.name + ' ' + p.mob).toLowerCase(), qy, toks) }))
    .filter((x) => x.r >= 0)
    .map((x) => x.p.name);
}

const out = {
  // a word from the front and a word from the end, in the wrong order
  scattered: search('monitor 60'),
  // the same two words the other way round find the same piece
  scattered_other_way: search('60 monitor'),
  // one word that several rows share: closest first
  shared_word: search('monitor'),
  // typing the name properly puts it first even though others contain it
  exact_first: search('monitor stand adjustable')[0],
  // a word that is not there anywhere means no match, not a wrong one
  nonsense: search('monitor xyzzy'),
  // part of a model number buried in the middle
  buried: search('e2422'),
  // nothing typed: everything, in the list's own order
  empty_keeps_order: search('').length === NAMES.length && search('')[0] === NAMES[0],
  // a party is still found by the number, not only the name
  by_mobile: searchParty('98765'),
  by_name_and_mobile: searchParty('varish 9876'),
  // ranks, so a change in the ordering rules is visible here
  ranks: {
    exact: SearchPick.rank('hp 22 inch led monitor', 'hp 22 inch led monitor', ['hp']),
    prefix: SearchPick.rank('hp 22 inch led monitor', 'hp 22', ['hp', '22']),
    word_start: SearchPick.rank('hp 22 inch led monitor', 'led', ['led']),
    inside: SearchPick.rank('hp 22 inch led monitor', 'nch', ['nch']),
    scattered: SearchPick.rank('hp 22 inch led monitor', 'hp monitor', ['hp', 'monitor']),
    missing: SearchPick.rank('hp 22 inch led monitor', 'hp dell', ['hp', 'dell']),
  },
};
console.log(JSON.stringify(out));
