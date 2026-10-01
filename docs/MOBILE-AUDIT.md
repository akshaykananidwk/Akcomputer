# AK Computer — મોબાઇલ ઑડિટ

**તારીખ:** 2026-10-01 · **માપ:** 390px પહોળાઈનો ખરો ફોન બ્રાઉઝર (Chromium)

આ યાદી કોડ વાંચીને નથી બની. **84 પાનાં ખરેખર ખોલીને**, દરેકમાં ટેબલની
પહોળાઈ, આડું ખસવું, અક્ષરનું માપ અને બટનની ઊંચાઈ **માપીને** બની છે.
(6 પાનાં ખૂલ્યાં નહીં — `brand`, `category`, `feedback`, `price`,
`product`, `service_report` — એ સીધાં ખૂલતાં નથી, બીજા પાનામાંથી ખૂલે છે.)

## ટૂંકો સાર

| | સંખ્યા |
|---|---|
| તપાસેલાં પાનાં | **78** |
| મોબાઇલમાં **સારાં** | **35** |
| મોબાઇલમાં **સુધારવાનાં** | **43** |

**એક જ ભૂલ 43 પાનાંમાં છે.** દરેક પાનાનું પોતાનું જુદું કારણ નથી — બધે
એક જ વસ્તુ છે: **પહોળું ટેબલ, જે ફોનમાં આડું ખસે છે.**

સામાન્ય ટેબલને `min-width: 550px` મળે છે, એટલે 390px ના ફોનમાં એ બેસતું
નથી અને એક ખાનામાં આડું સરકાવવું પડે છે. 7–9 કૉલમ હોય ત્યાં અડધી માહિતી
જમણી બાજુ છુપાયેલી રહે છે — દેખાતી જ નથી.

**જે 35 પાનાં સારાં છે એ બધાંમાં `rowlist` વપરાયેલું છે** — જે ફોનમાં
ટેબલને કાર્ડમાં ફેરવી નાખે છે. એટલે ઉપાય પહેલેથી આ સોફ્ટવેરમાં જ છે,
ફક્ત 43 પાનાંમાં વપરાયેલો નથી.

---

## 🔴 સ્તર 1 — સૌથી ખરાબ (21 પાનાં)

**7 થી 9 કૉલમ.** ફોનમાં અડધાથી વધુ માહિતી દેખાતી જ નથી.

| પાનું | કૉલમ | ટેબલ પહોળાઈ |
|---|---|---|
| `purchase_intel.php` | 9 | 962px |
| `collection.php` | 8 | 828px |
| `items.php` | 8 | 768px |
| `purchases.php` | 8 | 550px |
| `repairs.php` | 8 | 727px |
| `report_schedules.php` | 8 | 600px |
| `web_customers.php` | 8 | 645px |
| `amc.php` | 7 | 550px |
| `bank_accounts.php` | 7 | 550px |
| `cheques.php` | 7 | 550px |
| `estimates.php` | 7 | 550px |
| `expenses.php` | 7 | 550px |
| `handover.php` | 7 | 550px |
| `leads.php` | 7 | 550px |
| `sales_return.php` | 7 | 550px |
| `sites.php` | 7 | 550px |
| `stock.php` | 7 | 550px |
| `tasks.php` | 7 | 550px |
| `tickets.php` | 7 | 550px |
| `users.php` | 7 | 599px |
| `warranty.php` | 7 | 578px |

## 🟠 સ્તર 2 — મધ્યમ (19 પાનાં)

**5 થી 6 કૉલમ.** આડું ખસે છે, પણ મુખ્ય માહિતી મોટે ભાગે દેખાય છે.

| પાનું | કૉલમ | ટેબલ પહોળાઈ |
|---|---|---|
| `batches.php` | 6 | 550px |
| `day_close.php` | 6 | 555px |
| `follow_ups.php` | 6 | 550px |
| `journal.php` | 6 | 550px |
| `locations.php` | 6 | 550px |
| `market.php` | 6 | 550px |
| `net_connections.php` | 6 | 550px |
| `purchase_return.php` | 6 | 550px |
| `scaling.php` | 6 | 550px |
| `transfers.php` | 6 | 550px |
| `voice_calls.php` | 6 | 527px |
| `voice_setup.php` | 6 | 448px |
| `accounts.php` | 5 | 550px |
| `challans.php` | 5 | 550px |
| `companies.php` | 5 | 550px |
| `forecast.php` | 5 | 550px |
| `payment_methods.php` | 5 | 550px |
| `reports.php` | 5 | 550px |
| `stock_audit.php` | 5 | 550px |

## 🟡 સ્તર 3 — નાનું (3 પાનાં)

| પાનું | કૉલમ | ટેબલ પહોળાઈ |
|---|---|---|
| `ai_enrich.php` | 4 | 550px |
| `roles.php` | 4 | 550px |
| `catalog.php` | 0 | 0px |

`catalog.php` નું કારણ જુદું છે — ટેબલ નથી, પણ **62 બટન 36px થી નાનાં**
છે. આંગળીથી દબાવવાં અઘરાં.

---

## ✅ જે 35 પાનાં મોબાઇલમાં સારાં છે

`ai_categorize.php`, `approvals.php`, `assistant.php`, `bank_reconcile.php`, `campaigns.php`, `cash_bank.php`, `cost_analytics.php`, `customer.php`, `dashboard_customize.php`, `index.php`, `item_view.php`, `items_import.php`, `my_account.php`, `my_collections.php`, `my_jobs.php`, `my_stock.php`, `parties.php`, `payments.php`, `privacy.php`, `purchase_scan.php`, `purchase_view.php`, `referral.php`, `referrals.php`, `reminders.php`, `reviews.php`, `sale_view.php`, `sales.php`, `services.php`, `settings.php`, `terms.php`, `voice_in.php`, `voice_talk.php`, `voice_words.php`, `wa_inbox.php`, `web_orders.php`

આમાં એ બધાં છે જે આપણે આ મહિને ફરી ડિઝાઇન કર્યાં — ડેશબોર્ડ, વેચાણ યાદી,
Settings, પેમેન્ટ, પાર્ટી, રોકડ-બેંક — અને બીજાં જે પહેલેથી `rowlist`
વાપરતાં હતાં.

---

## ઉપાય — 43 જુદાં કામ નહીં, એક કામ

બધે એક જ ભૂલ છે, એટલે ઉપાય પણ એક જ હોવો જોઈએ:

**દરેક પહોળા ટેબલને `rowlist` વર્ગ આપવો.** એ મળતાં જ ફોનમાં ટેબલ
આપોઆપ કાર્ડમાં ફેરવાઈ જાય છે — કોડ નવો લખવાનો નથી, જે છે એ વાપરવાનું છે.

સાથે, આજે એક નવો વર્ગ **`rl-scan`** પણ ઉમેર્યો છે. ફરક આ છે:

| વર્ગ | ક્યારે | ફોનમાં શું દેખાય |
|---|---|---|
| `rowlist` | **એક રેકોર્ડ** જે ધ્યાનથી વાંચવાનો હોય | દરેક ખાનાનું નામ અને કિંમત — પૂરી વિગત |
| `rl-scan` | **યાદી** જેના પર નજર ફેરવવાની હોય | પહેલી લીટીએ નામ અને રકમ, બાકીનું નીચે ઝીણું |

રોકડ-બેંકની "Recent cash entries" યાદી `rowlist` થી **દરેક હરોળે પાંચ
લીટી** લેતી હતી — દસ એન્ટ્રી એટલે પચાસ લીટી. `rl-scan` થી **બે લીટી**
થઈ. યાદીની ઊંચાઈ અડધી થઈ ગઈ.

### સૂચવેલો ક્રમ

1. **સ્તર 1 નાં 21 પાનાં** — અહીં માહિતી ખરેખર છુપાઈ જાય છે
2. **`catalog.php` નાં બટન** — ગ્રાહક જુએ છે એ પાનું
3. **સ્તર 2 નાં 19 પાનાં**
4. **સ્તર 3 નાં 3 પાનાં**

દરેક પાનું આશરે 10–20 મિનિટનું કામ — ટેબલને વર્ગ આપવો, કઈ લીટી મુખ્ય છે
એ નક્કી કરવું, અને ફોનમાં માપીને જોવું.
