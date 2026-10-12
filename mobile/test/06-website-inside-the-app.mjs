// ---------------------------------------------------------------------------
// The website's own screens, opened INSIDE the app. A WebView has no printer
// dialog, no voice typing, no Web Bluetooth and cannot save a file a page
// made; the app lends its own through window.AKApp and assets/appshim.js
// plugs them in. Here the app's side is a stand-in that records each call,
// and the checks are that every website feature reaches it - and that an
// ordinary browser never gets the shim at all.
//
//     php -S 127.0.0.1:8088 -t .  ;  node mobile/test/06-website-inside-the-app.mjs
// ---------------------------------------------------------------------------
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';
import { execFileSync } from 'child_process';
const ROOT = new URL('../../', import.meta.url).pathname;
const php = (code) => execFileSync('php', ['-r', 'require "' + ROOT + 'includes/init.php"; ' + code]).toString().trim();
const S = 'http://127.0.0.1:8088/';
const SALE = php("echo (int)val('SELECT id FROM sales WHERE is_cancelled = 0 ORDER BY id DESC LIMIT 1');");

const b = await pkg.chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
let bad = 0;
const ok = (label, cond, extra) => { if (!cond) bad++; console.log((cond ? '  ✔ ' : '  ✘ ') + label + (extra !== undefined ? '  → ' + extra : '')); };

async function session(inApp) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 },
    userAgent: 'Mozilla/5.0 (Linux; Android 13) Chrome/120 Mobile' + (inApp ? ' AKShopApp/4.0' : '') });
  if (inApp) await ctx.addInitScript(() => {
    // the stand-in for MainActivity's bridge: it only writes down what was asked
    window.__calls = [];
    const log = (n) => (...a) => { window.__calls.push([n, ...a]); return n === 'btPrint' ? '✔ Printed on TEST' : (n === 'canListen' ? true : undefined); };
    window.AKApp = { home: log('home'), print: log('print'), saveFile: log('saveFile'), canListen: () => true,
                     listen: log('listen'), btPrint: log('btPrint') };
  });
  const p = await ctx.newPage();
  p.errs = []; p.on('pageerror', e => p.errs.push(e.message));
  p.on('dialog', d => d.accept());
  await p.goto(S + 'login.php'); await p.fill('input[name=username]', 'admin'); await p.fill('input[name=password]', 'Test@1234');
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  return p;
}
const calls = (p) => p.evaluate(() => window.__calls.map(c => c[0]));

console.log('=== An ordinary browser is untouched ===');
let w = await session(false);
await w.goto(S + 'index.php');
ok('no app shim on the website in Chrome', await w.evaluate(() => !window.__akshim && !document.getElementById('akHome')));
await w.context().close();

console.log('\n=== Inside the app ===');
const p = await session(true);
await p.goto(S + 'sales.php?action=new'); await p.waitForTimeout(500);
ok('the shim is on the page', await p.evaluate(() => window.__akshim === 1));
ok('a ⌂ button leads back to the app', await p.locator('#akHome').isVisible());
await p.click('#akHome');
ok('...and asks the app to go home', (await calls(p)).includes('home'));

console.log('\n--- voice typing (the bill form\'s 🎤) ---');
await p.click('#addItemsBtn'); await p.waitForTimeout(300);
const mic = p.locator('.i-micbtn').first();
ok('the 🎤 button is offered, though WebView has no speech of its own', await mic.count() > 0);
if (await mic.count()) {
  await mic.click(); await p.waitForTimeout(200);
  const id = await p.evaluate(() => (window.__calls.find(c => c[0] === 'listen') || [])[1]);
  ok('the phone\'s own voice typing is asked for', !!id, id);
  await p.evaluate((id) => window.__akSpeech(id, 'tempered glass'), id); await p.waitForTimeout(800);
  ok('what was said comes back into the page, no errors', p.errs.length === 0, p.errs.join(' | '));
}

console.log('\n--- printing ---');
await p.evaluate(() => window.print());
ok('Print opens Android\'s print screen', (await calls(p)).includes('print'));

console.log('\n--- a file the page made (CSV export) ---');
await p.evaluate(() => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['a,b\n1,2'], { type: 'text/csv' })); a.download = 'test.csv'; a.click(); });
await p.waitForTimeout(500);
const saved = await p.evaluate(() => window.__calls.find(c => c[0] === 'saveFile'));
ok('it is handed to the phone to save or share', !!saved && saved[1] === 'test.csv', saved && saved[1]);
ok('...with the file\'s real contents', !!saved && Buffer.from(saved[3], 'base64').toString() === 'a,b\n1,2');

console.log('\n--- a new tab (Excel / PDF report) ---');
await p.goto(S + 'index.php');
const before = p.url();
await p.evaluate(() => window.open('my_account.php', '_blank'));
await p.waitForTimeout(800);
ok('window.open opens it in place (there are no tabs in the app)', p.url().includes('my_account.php'), p.url());

console.log('\n--- logging out of the website from inside the app ---');
await p.evaluate(() => { window.__calls = []; });
await p.evaluate(() => { const a = document.createElement('a'); a.href = 'logout.php'; a.textContent = 'x'; document.body.appendChild(a); a.click(); });
await p.waitForTimeout(300);
ok('it goes back to the app instead of ending the session', (await calls(p)).includes('home') && !p.url().includes('login.php'));

if (+SALE) {
  console.log('\n--- the till receipt and a Bluetooth printer ---');
  await p.goto(S + 'receipt.php?id=' + SALE); await p.waitForTimeout(400);
  ok('the receipt page (no website header) gets the shim too', await p.evaluate(() => window.__akshim === 1));
  await p.click('#btPrint'); await p.waitForTimeout(400);
  const bt = await p.evaluate(() => window.__calls.find(c => c[0] === 'btPrint'));
  ok('🔵 Bluetooth goes to the phone\'s own Bluetooth', !!bt);
  ok('...with the ESC/POS bytes of the receipt', !!bt && Buffer.from(bt[1], 'base64')[0] === 0x1B);
  ok('...and the page says what happened', /Printed on TEST/.test(await p.locator('#btMsg').textContent()));
  await p.click('text=🖨️ Print'); await p.waitForTimeout(200);
  ok('🖨️ Print on the receipt opens Android\'s print screen', (await calls(p)).includes('print'));
}

console.log('\nJS errors: ' + (p.errs.length ? p.errs.join(' | ') : 'none'));
await b.close();
console.log(bad ? `\n✗ ${bad} FAILED` : '\n✓ ALL PASS');
process.exit(bad ? 1 : 0);
