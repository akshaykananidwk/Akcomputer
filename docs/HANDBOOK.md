# AK Computer — ટેકનિકલ હેન્ડબુક

**તારીખ:** 2026-10-01 · **આવૃત્તિ:** `APP_VERSION 2.3.0` (`includes/version.php`)

આ દસ્તાવેજ **તમારી ખરી સાઇટના કોડમાંથી** લખાયેલો છે — સામાન્ય જવાબ નથી.
દરેક ફાઇલનું નામ, ફંક્શન અને ટેબલ કોડમાં ખરેખર હાજર છે.

---

# 1 · બાંધકામ (Architecture)

## કઈ ટેકનોલોજી

| | |
|---|---|
| ભાષા | **PHP 8** (કોઈ framework નહીં — Laravel/CodeIgniter કંઈ નહીં) |
| ડેટાબેઝ | **MySQL / MariaDB**, PDO થી |
| Frontend | **સાદું HTML + CSS + JavaScript** — React/Vue/jQuery કંઈ નહીં |
| Build step | **કોઈ નહીં** — npm, webpack, composer કંઈ નથી |
| બહારની લાઇબ્રેરી | **એક પણ નહીં** — CDN પણ નહીં. PDF, બારકોડ, Excel, QR બધું જાતે લખેલું |

**આનો અર્થ શું:** તમે કોઈ પણ `.php` ફાઇલ ખોલીને બદલો અને સર્વર પર મૂકો —
**બસ, થઈ ગયું.** કંઈ compile કરવાનું નથી.

## દરેક પાનાની રચના

દરેક સ્ક્રીન **એક જ PHP ફાઇલ** છે, અને બધી એક જ રીતે શરૂ થાય છે:

```php
require_once __DIR__ . '/includes/init.php';   // બધું આમાંથી આવે
require_perm('sales.view');                     // પરવાનગી
... PHP લોજિક ...
include __DIR__ . '/includes/header.php';       // ઉપરનો ભાગ + મેનુ
... HTML ...
include __DIR__ . '/includes/footer.php';       // નીચેનો ભાગ + નેવિગેશન
```

`includes/init.php` ક્રમસર લોડ કરે છે: `config.php` → session → `db.php` →
**`money.php` (પૈસાના નિયમ, બીજા કંઈ પણ પહેલાં)** → `helpers.php` →
`errors.php` → `security.php` → `auth.php` → બાકીનું.

## ફોલ્ડરનું માળખું

```
/                    112 સ્ક્રીન — દરેક .php એક પાનું
  index.php          ડેશબોર્ડ (ઘરનું પાનું)
  sales.php          વેચાણ/બિલ          purchases.php   ખરીદી
  parties.php        ગ્રાહક/સપ્લાયર      items.php       આઇટમ
  cash_bank.php      રોકડ-બેંક          reports.php     35 રિપોર્ટ
  settings.php       બધી સેટિંગ         login.php       લોગિન
  ajax.php           બધી AJAX વિનંતી    api.php         મોબાઇલ એપ API
  cron.php           એક જ cron દરવાજો

includes/            50 સહાયક ફાઇલ — અહીં નિયમો રહે છે
  init.php           બુટસ્ટ્રેપ
  db.php             PDO જોડાણ, q() all() row() val()
  money.php          ⚠️ પૈસાના નિયમ — સૌથી સંવેદનશીલ
  helpers.php        adjust_stock(), setting(), money(), vault
  auth.php           લોગિન, can(), require_perm()
  security.php       2FA, rate limit, session
  header.php         ઉપરનો ભાગ + ડાબું મેનુ
  footer.php         નીચેનું 6-બટન નેવિગેશન
  menu.php           nav_menu() — મેનુની આખી યાદી
  gh_updater.php     ⚠️ GitHub થી જાતે અપડેટ + rollback
  dbmigrate.php      માઇગ્રેશન ચલાવનાર
  cron_jobs.php      18 આપોઆપ કામ
  pdf.php            બિલની PDF (જાતે લખેલી)
  whatsapp.php       વ્હોટ્સએપ મોકલવું
  version.php        APP_VERSION

assets/
  style.css          ⚠️ આખી સાઇટનો એક જ CSS
  app.js             ⚠️ આખી સાઇટનો એક જ JS

install/
  schema.sql         મૂળ ડેટાબેઝ
  upgrade_v1..v85.sql  84 માઇગ્રેશન
  migrate.php        માઇગ્રેશન ચલાવવાનું પાનું

uploads/             ⚠️ દુકાનનો ડેટા — git માં નથી, અપડેટ અડતું નથી
updates/restore/     ⚠️ અપડેટ પહેલાંના બેકઅપ
docs/                દસ્તાવેજ
tests/               3,324 ઑટોમેટિક ટેસ્ટ
config.php           ⚠️ પાસવર્ડ — git માં નથી, કદી અપડેટ થતી નથી
```

## હોસ્ટિંગ

`shop.akdwk.in` — સામાન્ય PHP હોસ્ટિંગ (cPanel જેવું). `config.php` માં:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', '...');  define('DB_USER', '...');  define('DB_PASS', '...');
define('BASE_URL', 'https://shop.akdwk.in');
define('APP_SECRET', '...');     // ⚠️ CSRF અને બધા ટોકનની ચાવી
define('APP_TZ', 'Asia/Kolkata');
```

---

# 2 · કંઈ પણ બદલવાની રીત

## 🔑 સૌથી અગત્યની વાત — તમે FTP વાપરતા નથી

તમારી સાઇટમાં **GitHub થી જાતે અપડેટ થવાની વ્યવસ્થા** બનેલી છે
(`includes/gh_updater.php`). એટલે આખી પ્રક્રિયા આ છે:

```
કોડ બદલો  →  GitHub પર push  →  સાઇટ પર Settings → Update દબાવો
```

**Update દબાવતાં શું થાય છે** (કોડ પ્રમાણે, ક્રમસર):

1. GitHub ને પૂછે — નવો commit છે?
2. **પહેલાં બેકઅપ લે:** આખો ડેટાબેઝ + બધી ફાઇલો → `updates/restore/<તારીખ>_<sha>/`
   **ડેટાબેઝ બેકઅપ નિષ્ફળ જાય તો અપડેટ આગળ વધતું જ નથી**
3. GitHub પરથી આખો zip ઉતારે
4. બધી ફાઇલો બદલે — **પણ `config.php`, `uploads/`, `updates/` ને અડતું નથી**
5. માઇગ્રેશન જાતે ચલાવે (નવાં ટેબલ/કૉલમ ઉમેરાય)
6. `opcache_reset()` — PHP નો cache સાફ

## દરેક પ્રકારના ફેરફાર માટે — કઈ ફાઇલ

| શું બદલવું છે | ફાઇલ | શું શોધવું |
|---|---|---|
| **ઘરનું પાનું** | `index.php` | `hm-money` (બે કાર્ડ), `hm-tiles` (ચાર ટાઇલ) |
| **ઉપરનો ભાગ** | `includes/header.php` | લાઇન 170 `<main class="content">` |
| **નીચેનું નેવિગેશન** | `includes/footer.php` | 6 બટનની યાદી |
| **ડાબું મેનુ** | `includes/menu.php` | `nav_menu()` ફંક્શન |
| **કોઈ પણ પાનું** | એ જ નામની `.php` | — |
| **રંગ / ડિઝાઇન** | `assets/style.css` | `:root` માં બધા રંગ ટોકન |
| **JavaScript** | `assets/app.js` | `Bill`, `Tables`, `FormGuard` |
| **બિલની PDF** | `includes/pdf.php` | — |
| **વ્હોટ્સએપનું લખાણ** | `includes/wa_lang.php` | ગુજરાતી/હિન્દી/અંગ્રેજી |
| **ફોનનું IVR** | `includes/voice_in.php` | — |

## લખાણ (text) બદલવું

લખાણ **કોડમાં જ** લખેલું છે. દા.ત. "Add expense" બદલવું હોય:

1. `grep -rn "Add expense" *.php` — ફાઇલ મળી જશે (`expenses.php:102`)
2. એ લીટી બદલો
3. Push → Update

## બટન બદલવું

બધાં બટન એક જ વર્ગ વાપરે છે:
`btn` · `btn-sm` (નાનું) · `btn-outline` (ખાલી) · `btn-danger` (લાલ) ·
`btn-success` (લીલું) · `btn-wa` (વ્હોટ્સએપ)

```html
<a class="btn btn-sm btn-outline" href="...">લખાણ</a>
```

## ફોર્મ બદલવું — ⚠️ એક નિયમ ભૂલવો નહીં

**દરેક POST ફોર્મમાં `<?= csrf_field() ?>` હોવું જ જોઈએ.** એ વગર ફોર્મ
કામ નહીં કરે (અને એ સલામતી માટે જ છે).

```html
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save">
  ...
</form>
```

**PHP બાજુ કંઈ લખવાનું નથી.** `csrf_check()` **આપોઆપ** ચાલે છે —
`includes/init.php` લાઇન 53 પર, દરેક POST વિનંતી માટે. ટોકન ખોટો હોય તો
પાનું ત્યાં જ અટકી જાય છે.

અપવાદ ફક્ત એ દરવાજા જે **પોતાની રીતે ઓળખ કરે છે** — `api.php` (Bearer
ટોકન), `razorpay_webhook.php` (HMAC સહી), વ્હોટ્સએપ/ટેલિગ્રામ/ફોનના
webhook. એ યાદી `includes/helpers.php` ના `csrf_check()` માં છે.

⚠️ **એટલે નવું webhook બનાવો તો જ એ યાદીને અડવું** — અને ત્યારે એની
પોતાની ઓળખ (સહી કે ટોકન) હોવી ફરજિયાત છે.

## નવું પાનું બનાવવું

નવી ફાઇલ `mypage.php`:

```php
<?php
require_once __DIR__ . '/includes/init.php';
require_perm('sales.view');          // કોઈ પરવાનગી
$page_title = 'મારું પાનું';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>મારું પાનું</h2>
  <p>...</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
```

મેનુમાં દેખાડવા `includes/menu.php` માં ઉમેરો.

## ફોટા / આઇકન

- **ફોટા** → `uploads/` (⚠️ git માં નથી, અપડેટ અડતું નથી — સીધા સર્વર પર)
- **આઇકન** → `includes/icons.php`, `icon('નામ', માપ)` થી વપરાય

---

# 3 · નવું ફીચર બનાવવાની આખી પ્રક્રિયા

### પગલું 1 — ડેટાબેઝ (જો નવું ટેબલ જોઈએ)

**કદી સીધું SQL ન ચલાવો.** નવી ફાઇલ `install/upgrade_v86.sql`:

```sql
-- v86: શું અને કેમ — ભવિષ્યમાં વાંચનાર માટે
CREATE TABLE IF NOT EXISTS my_table (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**કેમ સલામત છે:** માઇગ્રેશન **દર વખતે બધી ફાઇલો ફરી ચલાવે** છે, અને
"પહેલેથી છે" જેવી ભૂલો (કોડ 1050, 1060, 1061, 1062, 1091) માફ કરે છે.
એટલે બે વાર ચાલે તોય કંઈ બગડતું નથી.

### પગલું 2 — નિયમ `includes/` માં

લોજિક `includes/mymodule.php` માં મૂકો, પાનામાં નહીં. પછી `init.php` માં
`require_once` ઉમેરો.

### પગલું 3 — પરવાનગી

નવી પરવાનગી `mymodule.view` વાપરો, પાનામાં `require_perm('mymodule.view')`.
પછી **Settings → Roles** માં ભૂમિકાને આપો.

### પગલું 4 — સ્ક્રીન

ઉપર બતાવેલા ઢાંચા પ્રમાણે `.php` ફાઇલ.

### પગલું 5 — ટેસ્ટ

`tests/test_*.php` માં ઉમેરો, પછી `php tests/run.php`.

### પગલું 6 — Push → Update → **Migrate**

નવું `upgrade_v*.sql` હોય ત્યારે **Settings → Migrate પણ દબાવવું.**

---

# 4 · Admin Panel

## કોડ અડ્યા વગર જે બદલાય (Settings માં)

દુકાનનું નામ/સરનામું/GSTIN · લોગો · બિલનો ઢાંચો · કર દર · પેમેન્ટ રીત ·
બેંક ખાતાં · વપરાશકર્તા અને **40 મોડ્યૂલની 144 પરવાનગી** · બ્રાંચ ·
વ્હોટ્સએપની ચાવીઓ અને ઢાંચા · ફોન/IVR · AI ચાલુ-બંધ અને **બજેટ** ·
18 cron job નો સમય · બેકઅપ · રિમાઇન્ડરના નિયમ · ઉધાર મર્યાદા ·
**79 સેટિંગ કુલ**

## કોડમાં જ બદલવું પડે

પાનાનું માળખું · નવું ફીચર · બિલની PDF ની ડિઝાઇન · ગણતરીના નિયમ ·
મેનુનો ક્રમ · રંગ અને CSS · ડેટાબેઝનું માળખું

## નવી સેટિંગ ઉમેરવી

```php
// વાંચવી (બીજો argument = મૂળભૂત કિંમત)
$x = setting('my_setting', '1');
// લખવી
set_setting('my_setting', '0');
```
`settings.php` માં એનું ખાનું ઉમેરો. ⚠️ **ગુપ્ત ચાવી હોય તો**
`includes/helpers.php` માં `secret_setting_keys()` ની યાદીમાં નામ ઉમેરો —
તો એ ડેટાબેઝમાં `enc:v1:` તરીકે **encrypted** સચવાશે.

---

# 5 · ડેટાબેઝ

**106 ટેબલ.** મુખ્ય જૂથ:

| જૂથ | ટેબલ |
|---|---|
| પાયો | `settings` `companies` `locations` `users` `roles` |
| લોકો | `parties` (ગ્રાહક+સપ્લાયર એક જ ટેબલમાં) |
| માલ | `items` `stock` `stock_ledger` `item_serials` `item_batches` |
| વેચાણ | `sales` `sale_items` `sales_returns` `estimates` `challans` |
| ખરીદી | `purchases` `purchase_items` `purchase_returns` |
| પૈસા | `payments` `ledger` `bank_accounts` `expenses` `cheques` |
| સર્વિસ | `repairs` `warranties` `amc_contracts` `tasks` `tickets` |
| નોંધ | `activity_log` `cron_runs` |

## ⚠️ ત્રણ નિયમ જે કદી તોડવા નહીં

**1. ખાતાવહી (`ledger`) જ સત્ય છે.**
પાર્ટીનું બેલેન્સ કદી સીધું ન લખવું — `party_balance()` વાપરવું.

**2. સ્ટોક ફક્ત `adjust_stock()` થી જ બદલાય.**
`includes/helpers.php:237`. સીધું `UPDATE stock` કદી નહીં — એ `stock_ledger`
તોડી નાખે અને પછી કેમ ઘટ્યું એ કદી ખબર ન પડે.

**3. ડેટાબેઝ ફેરફાર ફક્ત માઇગ્રેશન ફાઇલથી.**
phpMyAdmin માં સીધો ફેરફાર = આવતા અપડેટે કદાચ નાશ.

## બેકઅપ

| રીત | ક્યારે |
|---|---|
| **Settings → Backup** | જાતે, ગમે ત્યારે |
| **રોજનું આપોઆપ** | cron `auto_backup`, ટેલિગ્રામ પર જાય, 7 નકલ રહે |
| **અપડેટ પહેલાં** | **આપોઆપ** — `updates/restore/` માં |

બેકઅપ **AES-256-CBC થી encrypted** હોય છે જો Settings માં passphrase મૂકી હોય.

---

# 6 · LIVE પર લઈ જવાની પ્રક્રિયા

```
1. કોડ બદલો  (local)
2. php tests/run.php          ← બધા ટેસ્ટ પાસ થાય?
3. git add -A && git commit -m "..."
4. git push
5. સાઇટ ખોલો → Settings → Update       ← બેકઅપ આપોઆપ લેવાય છે
6. નવું upgrade_v*.sql હોય તો → Settings → Migrate
7. ફોન અને કમ્પ્યૂટર બંનેમાં ખોલીને જુઓ
```

**ક્યારે Migrate પણ દબાવવું:** ફક્ત ત્યારે જ્યારે `install/` માં નવી
`upgrade_v*.sql` ફાઇલ ઉમેરાઈ હોય. ન ઉમેરાઈ હોય તો જરૂર નથી — અને બે વાર
દબાવો તોય કંઈ બગડતું નથી.

---

# 7 · કંઈ બગડે તો — પાછા જવાની રીત

### રીત 1 — Settings માંથી (સૌથી સહેલી)

**Settings → Update → Restore points.** દરેક અપડેટ પહેલાંનો સ્નેપશોટ
ત્યાં પડેલો છે. એક ક્લિકે બધી ફાઇલો પાછી આવે અને `opcache_reset()` થાય.

### રીત 2 — GitHub થી

```bash
git revert <commit>      # ઊંધો commit
git push
# પછી Settings → Update
```

### રીત 3 — ડેટાબેઝ પાછો

`updates/restore/<તારીખ>/db.sql.gz` (કે `.enc`) — એ ફાઇલ ઉતારીને
phpMyAdmin માં import કરવી.

⚠️ **ડેટાબેઝ પાછો લાવવો એટલે એ પછીનો બધો નવો ધંધો પણ જાય.** એટલે
ફક્ત ફાઇલોની ભૂલ હોય તો ફક્ત ફાઇલો પાછી લાવો.

---

# 8 · Cache

| પ્રકાર | છે? | કેવી રીતે સાફ |
|---|---|---|
| **PHP OPcache** | હા | અપડેટ અને rollback **આપોઆપ** `opcache_reset()` કરે |
| **બ્રાઉઝર (CSS/JS)** | હા | **આપોઆપ** — `asset_v()` ફાઇલનો સમય URL માં મૂકે |
| **ઍપ્લિકેશન** | થોડો | `uploads/cache/`, `uploads/qrcache/` — ભૂંસી શકાય |
| **ડેટાબેઝ cache** | નથી | — |
| **CDN** | નથી | — |

**એટલે ડિઝાઇન બદલ્યા પછી કંઈ કરવાનું નથી.** `style.css?v=1727...` આપોઆપ
બદલાય છે, જૂનું CSS કદી ચોંટી રહેતું નથી.

ગ્રાહક કહે "જૂનું દેખાય છે" તો એના ફોનનો બ્રાઉઝર cache — Ctrl+F5.

---

# 9 · સલામતી — જે પહેલેથી બનેલું છે

| | ક્યાં |
|---|---|
| **SQL Injection** | બધી ક્વેરી prepared statement — `q()`, `all()`, `row()` |
| **XSS** | `e()` ફંક્શન દરેક આઉટપુટ પર |
| **CSRF** | ફોર્મમાં `csrf_field()`; તપાસ `init.php:53` પર **આપોઆપ** |
| **લોગિન** | password_hash + **લોગિન throttle** |
| **2FA** | TOTP, ચાવી encrypted, backup codes |
| **Rate limit** | API ના દરેક દરવાજે, 429 સાથે |
| **પરવાનગી** | 144 પરવાનગી, `require_perm()` |
| **ગુપ્ત ચાવીઓ** | ડેટાબેઝમાં `enc:v1:` encrypted |
| **સેશન** | httponly + samesite + HTTPS પર secure |
| **નોંધ** | `activity_log` — દરેક મોટો ફેરફાર |

## તમારે પાળવાના નિયમ

- `config.php` **કદી** git માં ન નાખવી (પહેલેથી `.gitignore` માં છે)
- `APP_SECRET` બદલશો નહીં — બદલશો તો બધા ટોકન અને encrypted ચાવીઓ તૂટશે
- ફાઇલ permission: ફાઇલો `644`, ફોલ્ડર `755`, `uploads/` લખી શકાય એવું
- નવા ખાનામાં `e()` વાપરવાનું ભૂલવું નહીં
- નવા ફોર્મમાં `csrf_field()` ભૂલવું નહીં (તપાસ આપોઆપ છે, ખાનું નથી)
- નવી API માં rate limit ભૂલવું નહીં

---

# 10 · "ક્યાં શું બદલવું?" — ઝડપી નકશો

| બદલવું છે | જવું |
|---|---|
| ઘરનું પાનું | `index.php` |
| ઉપરનો ભાગ | `includes/header.php` |
| નીચેનું નેવિગેશન | `includes/footer.php` |
| ડાબું મેનુ | `includes/menu.php` → `nav_menu()` |
| લોગિન | `login.php` |
| ડેશબોર્ડના આંકડા | `includes/dashboard.php` |
| આઇટમ | `items.php` · `item_view.php` |
| ગ્રાહક | `parties.php` · `customer.php` |
| બિલ બનાવવું | `sales.php` |
| બિલ જોવું/PDF | `sale_view.php` · `includes/pdf.php` |
| રિપોર્ટ | `reports.php` · `includes/report_body.php` |
| સેટિંગ | `settings.php` |
| રોકડ-બેંક | `cash_bank.php` |
| **રંગ / ડિઝાઇન** | `assets/style.css` |
| **JavaScript** | `assets/app.js` |
| API | `api.php` · `includes/api_auth.php` |
| AJAX | `ajax.php` |
| ડેટાબેઝ | `install/upgrade_v86.sql` (નવી ફાઇલ) |
| વ્હોટ્સએપ લખાણ | `includes/wa_lang.php` |
| આપોઆપ કામ | `includes/cron_jobs.php` |
| **પૈસાના નિયમ** ⚠️ | `includes/money.php` |
| **સ્ટોકના નિયમ** ⚠️ | `includes/helpers.php` → `adjust_stock()` |

---

# 11 · મને કામ સોંપતી વખતે

તમારે **કંઈ ફાઇલ મોકલવાની જરૂર નથી** — મારી પાસે આખો કોડ છે. ફક્ત આટલું
કહો તો કામ ઝડપી અને સાચું થાય:

1. **કયું સ્ક્રીન** — "બિલ બનાવવાનું પાનું", કે ફોટો
2. **શું થાય છે અને શું થવું જોઈએ** — "આ આંકડો ખોટો આવે છે, આવો આવવો જોઈએ"
3. **ફોન કે કમ્પ્યૂટર** — કઈ જગ્યાએ દેખાય છે

**હું દર વખતે આટલું જાતે કરીશ:**

- બદલતાં પહેલાં **બીજે ક્યાં એ જ નિયમ વપરાય છે** એ શોધીશ
- પૈસા કે સ્ટોકને અડે તો **ટેસ્ટ પહેલાં લખીશ**
- **જૂના કોડ પર ટેસ્ટ ફેલ થાય છે** એ સાબિત કરીશ (નહીં તો ટેસ્ટ નકામો)
- **ખરા બ્રાઉઝરમાં ફોન અને કમ્પ્યૂટર બંનેમાં માપીશ** — ફોટો જોઈને નહીં
- આખો ટેસ્ટ સુટ ચલાવીશ
- commit માં **કેમ** કર્યું એ લખીશ, **શું** નહીं

---

# 12 · કોઈ પણ ફેરફારની SOP

```
 1. જરૂરિયાત સમજો      — બરાબર શું જોઈએ છે?
 2. ફાઇલ શોધો          — grep -rn "લખાણ" *.php
 3. જૂનો કોડ વાંચો      — કેમ આમ લખેલું છે?
 4. બેકઅપ              — અપડેટ જાતે લે છે, પણ મોટા ફેરફારે જાતે પણ લો
 5. ફેરફાર કરો          — ઓછામાં ઓછો, બીજે અડ્યા વગર
 6. ટેસ્ટ               — php tests/run.php
 7. ડેટાબેઝ            — ફક્ત નવી upgrade_v*.sql ફાઇલથી
 8. સલામતી ચકાસો       — csrf_field() છે? e() છે? પરવાનગી છે?
 9. ફોન + કમ્પ્યૂટર     — બંનેમાં ખોલીને જુઓ
10. git push
11. Settings → Update  — (cache આપોઆપ સાફ થાય છે)
12. નવી .sql હોય તો    — Settings → Migrate
13. લાઇવ પર ચકાસો      — ખરેખર કામ કરે છે?
14. બગડે તો            — Settings → Update → Restore point
```

---

# 13 · ડેવલપર માટેના નિયમ

1. **ચાલતું ફીચર કદી તૂટવું ન જોઈએ** — શંકા હોય તો ટેસ્ટ લખો
2. **બિનજરૂરી ફાઇલ ન અડો** — એક કામ, એક commit
3. **ડેટા કદી ન ભૂંસો** — માઇગ્રેશનમાં `DROP` કે `DELETE` નહીં
4. **સ્ટોક ફક્ત `adjust_stock()` થી**
5. **પૈસાના નિયમની બીજી નકલ ન લખો** — `money.php` માં જ
6. **દરેક ફોર્મ `csrf_field()`, દરેક આઉટપુટ `e()`**
7. **નવી API rate-limited**
8. **ગુપ્ત ચાવી `secret_setting_keys()` માં**
9. **મોબાઇલમાં ચકાસો** — 320px થી 1280px
10. **બેકઅપ વગર લાઇવ ડેટાબેઝ ન અડો**
11. **પૈસાના ફેરફાર પહેલાં ટેસ્ટ, અને એ ટેસ્ટ જૂના કોડ પર ફેલ થવો જોઈએ**
12. **commit માં "કેમ" લખો**

---

# 14 · જોખમ — જે ફાઇલો સૌથી સંભાળીને અડવી

| ફાઇલ | કેમ |
|---|---|
| `includes/money.php` | 🔴 આખી દુકાનનો હિસાબ. ભૂલ = ખોટા પૈસા |
| `includes/helpers.php` → `adjust_stock()` | 🔴 સ્ટોકનું એકમાત્ર દ્વાર |
| `install/*.sql` | 🔴 ડેટાબેઝ. ખોટું લખ્યું = ડેટા જાય |
| `config.php` | 🔴 પાસવર્ડ. `APP_SECRET` બદલ્યું = બધું તૂટે |
| `includes/auth.php` | 🟠 પરવાનગી. ભૂલ = કોઈ પણ કંઈ પણ જુએ |
| `includes/gh_updater.php` | 🟠 અપડેટ. તૂટે તો અપડેટ બંધ |
| `assets/style.css` | 🟡 આખી સાઇટનો એક જ CSS |
| `assets/app.js` | 🟡 આખી સાઇટનો એક જ JS |
| કોઈ પણ એક `.php` પાનું | 🟢 ફક્ત એ પાનું બગડે |

---

## એક છેલ્લી વાત

આ સોફ્ટવેરની સૌથી મોટી મિલકત એ છે કે એમાં **કોઈ બિનજરૂરી વસ્તુ નથી** —
ન framework, ન build step, ન બહારની લાઇબ્રેરી. એટલે પાંચ વર્ષ પછી પણ
કોઈ પણ PHP જાણનાર આ ખોલીને સમજી શકશે.

**એ મિલકત સાચવવા જેવી છે.** દરેક નવા કામ પહેલાં એક જ સવાલ:
*આનાથી દુકાનમાં બેઠેલા માણસનો સમય કે પૈસો બચશે?*
