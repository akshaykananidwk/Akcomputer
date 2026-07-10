# AK Computer — Multi-Location Shop Management (PHP + MySQL)

કોમ્પ્યુટર શોપ માટે સંપૂર્ણ billing + stock + staff + repairing + warranty management સિસ્ટમ.
સાદા shared hosting server પર ચાલે — ખાલી **PHP 7.4+ અને MySQL** જોઈએ, બીજી કોઈ library નહીં.
Mobile માં app જેવું ખૂલે છે (PWA — "Add to Home screen").

## Install (5 મિનિટ)

1. બધી files server પર upload કરો (cPanel File Manager / FTP).
2. Browser માં ખોલો: `https://tamaru-domain.com/install/`
3. DB details + admin username/password ભરો → **Install Now**.
4. Install થયા પછી `install/` folder server પરથી **delete કરી દો**.
5. Login કરી → **Settings** માં WhatsApp API ની Session ID અને API Key નાખો.

## Modules

| Module | શું કરે |
|---|---|
| **Sales / Billing** | Retail + B2B ભાવ, GST/Non-GST firm પસંદ કરી bill, print/PDF, WhatsApp send button, payment (cash/UPI/card/credit), credit days + due date |
| **Companies (2 firm)** | એક GST firm + એક વગર GST — અલગ invoice series, GST bill માં CGST/SGST આપોઆપ |
| **Estimates** | Quotation બનાવી WhatsApp મોકલો, પછી 1 click માં bill માં convert |
| **Sales / Purchase Return** | Stock આપોઆપ adjust, serial restore |
| **Purchase** | Supplier party (quick-add સાથે), serial numbers entry, credit term dropdown, supplier bill payment tracking |
| **Items** | Purchase / Selling / B2B — ત્રણ ભાવ, HSN, GST%, warranty months, min-stock alert, photo, "Show on website" flag |
| **Stock** | Location-wise (shop/branch/godown) + staff-held stock, full ledger (કોણે ક્યારે શું કર્યું), manual adjust |
| **Handover (OTP)** | Staff ને material આપતી વખતે WhatsApp OTP થી accept; staff જ્યાં સુધી હિસાબ ન આપે ત્યાં સુધી stock એના panel માં દેખાય; branch transfer પણ OTP થી |
| **Field Tasks** | Staff ને કામ assign (WhatsApp notify), site પર start/end time, material used → staff stock માંથી બાદ, service charge |
| **Repair Jobs** | Job sheet, status updates customer WhatsApp પર, બહાર repairing party ને આપો તો કેટલા દિવસ લગાડ્યા એનું tracking |
| **Warranty** | Serial number થી warranty check, claim → company મોકલો (courier + docket no), પાછું આવે (courier + દિવસ), company-wise TAT report |
| **Payments** | Receivables/Payables, overdue highlight, WhatsApp payment reminder button, party ledger |
| **Reports** | Daily sales, item-wise sales, purchase, GST (firm-wise, slab-wise), Profit (permission હોય તો જ), staff stock, repair party TAT, warranty company TAT, low stock |
| **Users & Roles** | Department-wise roles (Admin/Manager/Sales/Purchase/Accounts/Field) + user દીઠ extra permission; sales staff ને profit ન દેખાય, પોતાના જ records દેખાય |
| **Website Catalog** | `catalog.php` — public page, "Show on website" કરેલી items photo+price સાથે |

## WhatsApp Integration (bulk.akdwk.in API)

Settings માં API URL / Session ID / API Key નાખો. પછી આપોઆપ:
- Login OTP (ચાલુ કરો તો), Forgot-password OTP
- Stock handover OTP
- Bill / Estimate WhatsApp મોકલવું (customer ને link મળે)
- Repair + Warranty status updates
- Payment reminder
- Task assignment notification

## Security

- Passwords hashed (bcrypt), session-based login
- દરેક form પર CSRF protection
- Permission check દરેક page/action પર
- Stock ફેરફારો transaction માં — અડધો હિસાબ કદી ન થાય
- `uploads/` માં PHP execute block, `config.php` browser થી block
- Activity log — કોણે શું કર્યું

## Files

- `install/` — installer + `schema.sql` (install પછી delete કરવું)
- `includes/` — db, auth/permissions, helpers, WhatsApp API, layout
- `assets/` — CSS/JS/icons (કોઈ CDN નહીં — બધું local)
- Root — દરેક module એક file (sales.php, purchases.php, ...)

## Default roles

Admin (બધું) · Manager · Sales Staff · Purchase Staff · Accounts · Field Staff — Roles page માંથી બદલી શકાય, નવા બનાવી શકાય.
