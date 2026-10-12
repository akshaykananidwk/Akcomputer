// ---------------------------------------------------------------------------
// One app, every shop on the software. A shop's staff type the shop's code
// and their username OR e-mail; the app finds the shop on the main address
// and from then on talks only to that shop. A phone never mixes two shops:
// work still waiting for one shop is never sent to another.
//
//     php -S 127.0.0.1:8088 -t .  ;  php -S 127.0.0.1:8099 -t mobile/www
//     node mobile/test/07-any-shop-one-app.mjs      (uses the "rammobile" test shop)
// ---------------------------------------------------------------------------
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';
import { execFileSync } from 'child_process';
const ROOT = new URL('../../', import.meta.url).pathname;
const shopPhp = (code) => execFileSync('php', ['-r', 'require "' + ROOT + 'includes/init.php"; ' + code],
  { env: { ...process.env, TENANT_HOST: 'rammobile.localhost' } }).toString().trim();
const OLD = JSON.parse(shopPhp(`echo json_encode(row("SELECT password, email FROM users WHERE username = 'owner'"));`));
shopPhp(`q("UPDATE users SET password = ?, email = 'owner.ram@example.com' WHERE username = 'owner'", [password_hash('Ram@12345', PASSWORD_DEFAULT)]);`);

const b = await pkg.chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
const p = await ctx.newPage();
let errs = [], bad = 0;
p.on('pageerror', e => errs.push(e.message));
const ok = (label, cond, extra) => { if (!cond) bad++; console.log((cond ? '  ✔ ' : '  ✘ ') + label + (extra !== undefined ? '  → ' + extra : '')); };
const login = async (code, user, pass) => {
  if (await p.locator('#l_other').isVisible().catch(() => false)) await p.click('#l_other');
  await p.fill('#l_shopcode', code); await p.fill('#l_user', user); await p.fill('#l_pass', pass);
  await p.click('#l_go'); await p.waitForTimeout(4500);
};

try {
  await p.goto('http://127.0.0.1:8099/index.html');
  await p.evaluate(async () => { await DB.clearAll(); }); await p.reload(); await p.waitForTimeout(500);
  await p.click('#l_adv'); await p.fill('#l_srv', 'http://127.0.0.1:8088');

  console.log('=== The login screen ===');
  ok('the shop code is one tap away ("Other shop?"), not a third box for everyone', await p.locator('#l_shopcode').isHidden() && await p.locator('#l_other').isVisible());
  ok('asks for username OR e-mail', await p.locator('#l_shopcode').count() === 1
     && /e-mail/i.test(await p.locator('label:has-text("Username")').textContent()));
  ok('has "Forgot password?"', await p.locator('#l_forgot').count() === 1);

  console.log('\n=== A wrong shop code says so ===');
  await login('nosuchshop', 'owner', 'Ram@12345');
  ok('no such shop: told plainly', /No shop with the code/.test(await p.locator('#l_err').textContent()), await p.locator('#l_err').textContent());

  console.log('\n=== Another shop, logging in with an e-mail ===');
  await login('rammobile', 'Owner.Ram@Example.com', 'Ram@12345');
  ok('logged in to that shop', await p.locator('[data-act="newbill"]').count() > 0, await p.locator('#l_err').count() ? await p.locator('#l_err').textContent() : '');
  const st = await p.evaluate(async () => ({ server: await DB.meta('server', ''), shop: await DB.meta('shop', ''), items: await DB.count('items') }));
  ok('the app now talks to that shop\'s own address', st.server === 'http://rammobile.localhost:8088', st.server);
  ok('...and shows that shop\'s name and items', !!st.shop && st.items >= 0, st.shop + ' / ' + st.items + ' items');

  console.log('\n=== Work waiting for one shop never goes to another ===');
  await p.evaluate(async () => {
    await DB.put('outbox', { uuid: 'test-wait-1', kind: 'expense', body: { amount: 1 }, state: 'pending', attempts: 0, created_at: new Date().toISOString() });
    await DB.setMeta('token_bad', 1);
  });
  await p.evaluate(() => App.go('login')); await p.waitForTimeout(400);
  await p.click('#l_adv'); await p.fill('#l_srv', 'http://127.0.0.1:8088');
  await login('', 'admin', 'Test@1234');
  const msg = await p.locator('#l_err').textContent();
  ok('logging in to a different shop is refused while work waits', /still waiting for the other shop/.test(msg), msg);
  ok('...and the waiting entry is still on the phone', await p.evaluate(async () => !!(await DB.get('outbox', 'test-wait-1'))));
  await p.evaluate(async () => { await DB.del('outbox', 'test-wait-1'); });
  await login('', 'admin', 'Test@1234');
  const st2 = await p.evaluate(async () => ({ server: await DB.meta('server', ''), code: await DB.meta('shopcode', 'x') }));
  ok('with nothing waiting, the switch is allowed and starts clean', st2.server === 'http://127.0.0.1:8088' && st2.code === '', JSON.stringify(st2));
} finally {
  shopPhp(`q("UPDATE users SET password = ?, email = ? WHERE username = 'owner'", [${JSON.stringify(OLD.password)}, ${OLD.email === null ? 'null' : JSON.stringify(OLD.email)}]);
           q("UPDATE api_tokens SET revoked = 1 WHERE device = 'Android'");`);
}

console.log('\nJS errors: ' + (errs.length ? errs.join(' | ') : 'none'));
await b.close();
console.log(bad ? `\n✗ ${bad} FAILED` : '\n✓ ALL PASS');
process.exit(bad ? 1 : 0);
