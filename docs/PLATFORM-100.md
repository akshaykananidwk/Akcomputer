# AK Computer — 100 Features to become a Computer Retail + Service Platform

**તારીખ:** 2026-09-28 · **બ્રાન્ચ:** `claude/multi-location-billing-system-rs1ly6`

આ યાદી **હવામાં નથી બનાવી.** તમારા કોડમાં **104 ટેબલ** અને **102 સ્ક્રીન** છે — એટલે
પહેલાં એ ગણ્યું કે તમારી પાસે **શું પહેલેથી છે**, પછી જ 100 ફીચર લખ્યાં. તમે
મોકલેલી ~250 example લાઇનમાંથી **મોટા ભાગની પહેલેથી બની ગયેલી છે** — એ ફરીથી
વેચવી એ તમારો પૈસો બગાડવો થાત.

**નિશાની:**
- 🔴 **નવું** — અત્યારે કંઈ નથી
- 🟡 **અડધું** — ટેબલ કે પાસેનું ફીચર છે, બાકીનું ખૂટે છે (સસ્તું કામ)
- ✅ — પહેલેથી છે (આ 100 માં **નથી**; Part 0 માં યાદી છે)

---

# PART 0 — તમારી પાસે પહેલેથી શું છે (ઑડિટ)

તમારી example યાદીની સામે કોડમાં તપાસેલું. આ બધું **બની ગયેલું છે, ફરી બનાવવાનું નથી:**

| તમે માગ્યું | તમારી પાસે છે | પુરાવો |
|---|---|---|
| Fast POS, Barcode, QR, Serial Billing | ✅ | `sales.php`, `item_serials`, `barcode.php` |
| Multiple/Split Payment, Credit Sale | ✅ | `sale_payment_lines()`, `payments` |
| Customer Ledger, Invoice PDF, WhatsApp Invoice | ✅ | `parties.php`, `includes/pdf.php`, `sale_whatsapp_send()` |
| GST Invoice, Estimate/Quotation, Delivery Challan | ✅ | `companies.is_gst`, `estimates`, `challans` |
| Sales Return, Credit/Debit Note | ✅ | `sales_returns`, `purchase_returns`, `*_credits` |
| Bill Park, Trade-in, Kit/Combo, Signature, Day Close | ✅ | `parked_bills`, `trade_ins`, `item_kit_parts`, `day_closes` |
| SKU/Brand/Model/Serial/Batch/Warranty | ✅ | `items`, `item_serials`, `item_batches` |
| Purchase/Selling/MRP/Dealer/B2B price | ✅ | `items.b2b_price`, `dealer_price()`, `web_accounts.discount_pct` |
| Stock Location, Godown, Branch, Transfer | ✅ | `locations`, `handovers`, `transfers.php` |
| Low/Out/Overstock, Dead Stock, Aging, Valuation | ✅ | `dash_stock()`, `dead_stock_rows()`, `stock_cost_layers` |
| Physical Audit, Barcode Count | ✅ | `stock_counts`, `stock_count_items`, `stock_audit.php` |
| Purchase Invoice/Return/Payment, Supplier Ledger | ✅ | `purchases`, `purchase_returns`, `parties` |
| Warranty Registration/Claim/RMA/History | ✅ | `warranty_claims`, `serial_chain()`, `warranty.php` |
| Service Ticket, Job Card, Photos, Technician, Parts | ✅ | `repairs`, `repair_photos`, `repair_materials`, `repair_checklist` |
| AMC, Site Survey vault, Installation jobs | ✅ | `amc_contracts`, `sites`, `tasks` |
| CRM: history, category, credit limit, birthday | ✅ | `customer.php`, `parties.credit_limit`, `follow_ups`, `leads` |
| E-commerce: catalog, cart, order, reviews, wishlist | ✅ | `catalog.php`, `web_orders`, `product_reviews` |
| Dealer/B2B login + special price | ✅ | `web_accounts`, `?r=wlogin` |
| WhatsApp bot, catalog-in-chat, campaigns, opt-out | ✅ | `wa_bot.php`, `wa_portal.php`, `campaigns`, `wa_prefs` |
| AI: assistant, bill OCR, categories, forecast, market | ✅ | `sales_assist.php`, `bill_scan.php`, `ai_categorize.php`, `forecast.php` |
| Accounting: COA, Journal, P&L, Bank, Cheque, Reconcile | ✅ | `chart_of_accounts`, `journal_lines`, `cheques`, `bank_reconcile.php` |
| RBAC, 2FA, Login history, Audit log, Approvals, Backup | ✅ | `roles`, `totp_backup_codes`, `activity_log`, `edit_requests` |
| API tokens, Webhooks, Rate limiting, Usage metering | ✅ | `api_tokens`, `webhooks`, `api_rate_ok()`, `api_usage` |
| Mobile app (offline billing + collection + customer mode) | ✅ | `mobile/` — Android APK v3.0 |
| Scheduled reports, Custom report builder, Cron manager | ✅ | `report_schedules`, `custom_reports`, `cron_manager.php` |

**અને બે વસ્તુ ખાસ ધ્યાન આપવા જેવી —** આ ટેબલ **v19 માં બન્યાં પણ કોઈ કોડ વાપરતો નથી**
(dead schema). એટલે એ ફીચર **અડધાં બનેલાં** છે અને પૂરાં કરવા સસ્તાં પડશે:

| ટેબલ | શું હોવું જોઈતું | હાલત |
|---|---|---|
| `stock_reservations` | Reserved Stock | ટેબલ છે, PHP માં 0 વપરાશ |
| `location_bins` + `stock_bins` | Rack / Bin location | ટેબલ છે, PHP માં 0 વપરાશ |

---

# 100 FEATURES

> દરેક ફીચર **નવું કે અડધું** જ છે. પહેલેથી બની ગયેલું એકેય નથી. કોઈ repeat નથી.

---

## CATEGORY 1 — BILLING / POS (6)

### #1 · Keyboard-only Counter Mode 🔴
**Category:** Billing/POS
**શું છે?** માઉસ વગર, ફક્ત કીબોર્ડથી આખું બિલ — F-key અને Enter પર ચાલતું POS layer.
**શું કામ કરે છે?** `F2` આઇટમ, `F3` ગ્રાહક, `F4` પેમેન્ટ, `F9` સેવ; Tab-order fix; દરેક સ્ક્રીન પર `?` દબાવો એટલે shortcut યાદી.
**Real-world Use Case:** તહેવારમાં કાઉન્ટર પર લાઇન હોય ત્યારે માઉસ ઉપાડવાનો સમય નથી — બારકોડ ગન + કીબોર્ડ પૂરતું.
**Customer:** ઝડપી બિલ, ઓછી લાઇન.
**Staff:** એક બિલ ~40% ઝડપી; નવા સ્ટાફને shortcut યાદી on-screen.
**Owner:** એ જ સ્ટાફથી વધુ બિલ — તહેવારમાં માણસ વધારવો ન પડે.
**Revenue Impact:** પરોક્ષ ઊંચી (peak hour throughput ↑).
**Cost Saving:** સીઝનમાં એક extra કાઉન્ટર-બોય નહીં (~₹12,000/મહિનો).
**Automation:** કંઈ નહીં — pure UX.
**AI Opportunity:** નથી (જાણી જોઈને — કીબોર્ડ shortcut AI નું કામ નથી).
**WhatsApp:** લાગુ નથી.
**Mobile/PWA:** ટેબ્લેટ સાથે external કીબોર્ડ.
**DB Tables:** કોઈ નવું નહીં (`user_preferences` માં shortcut પસંદ).
**APIs:** કોઈ નહીં (client-side).
**Security:** shortcut પરમિશન bypass ન કરે — દરેક action પર `can()` એ જ રહે.
**Complexity:** Easy
**Priority:** P0
**Monetization:** "Pro POS" પેકેજ.
**Scalability:** સંપૂર્ણ client-side, કોઈ લોડ નહીં.
**Example:** બારકોડ સ્કેન → qty ટાઇપ → `F4` → `Enter` → બિલ સેવ. 6 સેકન્ડ.

### #2 · Offline-first Counter (Web) 🟡
**Category:** Billing/POS
**શું છે?** વેબસાઈટનું બિલિંગ પાનું નેટ ગયા પછી પણ ચાલે — મોબાઇલ એપમાં જે છે એ જ, બ્રાઉઝરમાં.
**શું કામ કરે છે?** Service Worker + IndexedDB + `client_uuid` outbox; નેટ આવે એટલે એ જ `?r=sales` પર ચડે.
**Real-world Use Case:** દ્વારકામાં લાઇટ/નેટ જાય ત્યારે કાઉન્ટર બંધ ન થાય.
**Customer:** બિલ મળે જ, નેટની ચિંતા નહીં.
**Staff:** "નેટ નથી" એ બહાનું ખતમ.
**Owner:** નેટ ગયું એટલે વેચાણ બંધ — એ જોખમ જાય.
**Revenue Impact:** ઊંચી (downtime = 0).
**Cost Saving:** બેકઅપ ઇન્ટરનેટ લાઇન ન લેવી પડે.
**Automation:** Sync આપોઆપ, backoff સાથે.
**AI Opportunity:** નથી.
**WhatsApp:** નેટ આવ્યા પછી બિલ WhatsApp પર જાય (કતારમાં).
**Mobile/PWA:** આ જ ફીચરનો પાયો — `manifest.json` + install prompt.
**DB Tables:** કોઈ નવું નહીં (`sales.client_uuid` છે).
**APIs:** હાલની `api.php?r=sales` — નવું કંઈ નહીં.
**Security:** SW ફક્ત same-origin cache; ટોકન SW માં કદી નહીં.
**Complexity:** Medium (એપનો `sync.js` reuse થાય)
**Priority:** P0
**Monetization:** બધા પ્લાનમાં — retention feature.
**Scalability:** સર્વર લોડ ઘટે (કેશ થયેલી masters).
**Example:** લાઇટ ગઈ, ઇન્વર્ટર પર લેપટોપ ચાલુ — 14 બિલ બન્યાં, નેટ આવતાં બધાં ચડી ગયાં.

### #3 · Price Override Approval at the Counter 🟡
**Category:** Billing/POS
**શું છે?** સ્ટાફ minimum margin થી નીચે ભાવ નાખે તો બિલ **રોકાય** અને માલિકની મંજૂરી માગે.
**શું કામ કરે છે?** `enforce_min_margin()` હાલ ફક્ત ભાવ **વધારે** છે; આ line-level guard ઉમેરે — warn → block → OTP/PIN approval.
**Real-world Use Case:** ₹50,000 ના લેપટોપમાં સ્ટાફ ₹48,000 નાખે — margin ખતમ.
**Customer:** ભાવ સાચો અને એકસરખો.
**Staff:** શું ચાલશે એ સ્પષ્ટ; ના પાડવાની જવાબદારી સિસ્ટમ લે.
**Owner:** માર્જિન લીકેજ બંધ — સૌથી સીધો નફો.
**Revenue Impact:** સીધી ઊંચી (દર બિલે 1-2% margin બચે).
**Cost Saving:** ચોરી/મિત્રભાવ ડિસ્કાઉન્ટ બંધ.
**Automation:** હદ વટાય એટલે માલિકને WhatsApp/Telegram તરત.
**AI Opportunity:** કયા સ્ટાફ/કયા બ્રાન્ડમાં વારંવાર થાય છે એ pattern (deterministic પહેલાં, AI પછી).
**WhatsApp:** "Approve ₹48,000?" — Yes/No બટન.
**Mobile/PWA:** માલિક ફોનથી મંજૂરી આપે.
**DB Tables:** `edit_requests` reuse + `price_overrides` (નવું).
**APIs:** `POST ?r=override_request`, `POST ?r=override_decide`.
**Security:** મંજૂરી ફક્ત `sales.discount_override` પરમિશન; દરેક નિર્ણય `activity_log` માં.
**Complexity:** Medium
**Priority:** P0
**Monetization:** "Profit Guard" add-on.
**Scalability:** પ્રતિ-લાઇન એક ચેક, negligible.
**Example:** margin 2% → લાલ બોક્સ "માલિકની મંજૂરી જોઈશે" → માલિકના ફોન પર બટન → Approve → બિલ સેવ.

### #4 · Layaway / Advance Booking Bill 🔴
**Category:** Billing/POS
**શું છે?** ગ્રાહક એડવાન્સ આપીને માલ **બુક** કરે; માલ શેલ્ફ પર reserved થાય, બાકી પછી.
**શું કામ કરે છે?** `stock_reservations` (અડધું બનેલું ટેબલ) વાપરીને qty રોકે; બાકી આવે એટલે બિલ પૂરું.
**Real-world Use Case:** "RTX કાર્ડ આવે એટલે મારું રાખો, ₹5,000 એડવાન્સ."
**Customer:** માલ પક્કો; ભાવ lock.
**Staff:** બુકિંગ ભૂલાય નહીં; કોને આપવાનું એ સ્પષ્ટ.
**Owner:** એડવાન્સ = ફ્રી working capital; વેચાણ પક્કું.
**Revenue Impact:** ઊંચી (ઓર્ડર lock, ગ્રાહક બીજે ન જાય).
**Cost Saving:** ખોટો માલ ન મંગાવાય.
**Automation:** બાકી રકમનું રિમાઇન્ડર; બુકિંગ expiry પર stock છૂટે.
**AI Opportunity:** કયું બુકિંગ પડી ભાંગશે એનું જોખમ (ચૂકવણીની ટેવ પરથી).
**WhatsApp:** "તમારો માલ આવી ગયો, ₹45,000 બાકી છે."
**Mobile/PWA:** બુકિંગ યાદી ફોનમાં.
**DB Tables:** `stock_reservations` (wire up), `sales.reserved_until` (નવું કૉલમ).
**APIs:** `POST ?r=reserve`, `GET ?r=reservations`.
**Security:** reservation છોડવી = `stock.adjust` પરમિશન; audit log.
**Complexity:** Medium
**Priority:** P1
**Monetization:** core પ્લાન.
**Scalability:** ટેબલ પહેલેથી indexed.
**Example:** ₹5,000 એડવાન્સ → 15 દિવસ reserved → ન આવ્યા તો stock પાછું available, ગ્રાહકને જાણ.

### #5 · Counter Hand-off / Shift Handover 🟡
**Category:** Billing/POS
**શું છે?** સવારનો સ્ટાફ સાંજના સ્ટાફને ગલ્લો સોંપે — રોકડ ગણીને, સહી સાથે.
**શું કામ કરે છે?** `day_closes` નો shift-level ભાઈ: opening/closing per shift per user, denomination સાથે.
**Real-world Use Case:** બે shift હોય ત્યારે "ફરક કોના સમયનો?" એ સવાલનો જવાબ.
**Customer:** લાગુ નથી.
**Staff:** પોતાના shift પૂરતી જ જવાબદારી — રક્ષણ પણ છે.
**Owner:** ફરક કોના shift માં થયો એ તરત.
**Revenue Impact:** પરોક્ષ (leakage ↓).
**Cost Saving:** રોકડની ઘટ પકડાય.
**Automation:** shift બંધ થાય એટલે માલિકને summary.
**AI Opportunity:** કયા shift માં વારંવાર ફરક — anomaly detection.
**WhatsApp:** "Shift 1 બંધ: ગણ્યા ₹42,300, ચોપડે ₹42,300 ✔"
**Mobile/PWA:** ફોનથી ગલ્લો ગણવો.
**DB Tables:** `shift_closes` (નવું) — `day_closes` નું schema reuse.
**APIs:** `POST ?r=shift_close`.
**Security:** પોતાનું shift જ બંધ કરી શકે; edit ફક્ત admin.
**Complexity:** Easy
**Priority:** P1
**Monetization:** multi-staff પ્લાન.
**Scalability:** દિવસના 2-3 રો.
**Example:** 2 PM shift change → સાંજનો સ્ટાફ opening ₹42,300 થી શરૂ કરે.

### #6 · Bill-level Serial Warranty Card (auto PDF) 🟡
**Category:** Billing/POS
**શું છે?** બિલ સાથે અલગ **વોરંટી કાર્ડ** PDF — દરેક સિરિયલ, વોરંટી તારીખ અને શરત સાથે.
**શું કામ કરે છે?** બિલના serial + `item_serials.warranty_expiry` માંથી એક પાનું બને, QR સાથે જે warranty portal ખોલે.
**Real-world Use Case:** CCTV માં 8 કેમેરા — દરેકની વોરંટી અલગ; ગ્રાહકને એક કાગળ જોઈએ.
**Customer:** વોરંટી ક્યારે પૂરી થાય એ કાળા-ધોળામાં; QR થી ક્યારેય તપાસી શકે.
**Staff:** "વોરંટીની તારીખ કઈ?" એ સવાલ બંધ.
**Owner:** વોરંટી વિવાદ ઘટે; પ્રોફેશનલ દેખાવ.
**Revenue Impact:** મધ્યમ (વિશ્વાસ → repeat).
**Cost Saving:** ખોટા વોરંટી ક્લેમ ઘટે.
**Automation:** બિલ સેવ થતાં જ બને અને WhatsApp પર જાય.
**AI Opportunity:** નથી.
**WhatsApp:** બિલ PDF સાથે વોરંટી કાર્ડ PDF.
**Mobile/PWA:** QR સ્કેન → status.
**DB Tables:** કોઈ નવું નહીં.
**APIs:** `GET ?r=warranty_card&sale_id=`.
**Security:** share_token થી જ ખૂલે (હાલની પદ્ધતિ).
**Complexity:** Easy
**Priority:** P1
**Monetization:** core.
**Scalability:** PDF cache.
**Example:** 8 કેમેરા, 8 સિરિયલ, બધાની expiry 2028-09-28 — એક પાનું, એક QR.

---

## CATEGORY 2 — INVENTORY (8)

### #7 · Rack / Bin Location 🟡
**Category:** Inventory
**શું છે?** "કબાટ 3, ખાનું B" — માલ **ક્યાં પડ્યો છે** એ.
**શું કામ કરે છે?** `location_bins` + `stock_bins` (અડધાં બનેલાં ટેબલ) wire up; બિલ/પિકિંગ વખતે bin દેખાય.
**Real-world Use Case:** 3,000 SKU માં "HP 680 કારતૂસ ક્યાં છે?" શોધવામાં 5 મિનિટ જાય.
**Customer:** ઝડપી ડિલિવરી.
**Staff:** નવો સ્ટાફ પણ માલ શોધી શકે — training સમય ઘટે.
**Owner:** માણસનો સમય બચે; "નથી" કહીને ગુમાવેલું વેચાણ ઘટે.
**Revenue Impact:** મધ્યમ (શેલ્ફ પર હોવા છતાં "નથી" કહેવાનું બંધ).
**Cost Saving:** ઊંચી — રોજ કલાકો બચે.
**Automation:** નવો માલ આવે એટલે bin સૂચવે (જ્યાં એ જ આઇટમ પડ્યો છે).
**AI Opportunity:** fast-moving માલ આગળના bin માં — slotting optimisation.
**WhatsApp:** લાગુ નથી.
**Mobile/PWA:** ફોનમાં આઇટમ શોધો → bin દેખાય (એપમાં Stock સ્ક્રીન પર).
**DB Tables:** `location_bins`, `stock_bins` (પહેલેથી છે!).
**APIs:** `?r=sync` માં bin ઉમેરવું.
**Security:** `stock.view` પૂરતું.
**Complexity:** Easy (ટેબલ તૈયાર છે)
**Priority:** P0
**Monetization:** core.
**Scalability:** પહેલેથી indexed.
**Example:** "HP 680 Black — રેક 3, ખાનું B, 8 નંગ".

### #8 · Stock Condition Classes (New / Open Box / Demo / Refurb / Damaged) 🔴
**Category:** Inventory
**શું છે?** એક જ આઇટમના **અલગ હાલતના** પીસ, અલગ ભાવે.
**શું કામ કરે છે?** `item_serials.condition` + condition-wise ભાવ; બિલ પર હાલત છપાય.
**Real-world Use Case:** Open-box લેપટોપ ₹3,000 સસ્તું; demo કેમેરા exhibition પછી વેચવો.
**Customer:** સસ્તો વિકલ્પ, પણ **પ્રામાણિકપણે** લખેલો.
**Staff:** કયો પીસ કઈ હાલતનો — ગૂંચવાડો બંધ.
**Owner:** demo/open-box માલ પડી ન રહે; damaged માલ ચોપડે અલગ.
**Revenue Impact:** ઊંચી (નવો price segment ખૂલે).
**Cost Saving:** ઊંચી (dead stock → રોકડ).
**Automation:** demo 60 દિવસ જૂનો થાય એટલે "વેચી નાખો" alert.
**AI Opportunity:** હાલત પ્રમાણે યોગ્ય discount % સૂચવે.
**WhatsApp:** "Open-box લેપટોપ ₹3,000 સસ્તું — વોરંટી પૂરી."
**Mobile/PWA:** કેટલોગમાં "Open Box" ફિલ્ટર.
**DB Tables:** `item_serials.condition` (નવું કૉલમ), `condition_prices` (નવું).
**APIs:** `?r=catalog` માં condition.
**Security:** condition બદલવી = `stock.adjust` + audit.
**Complexity:** Medium
**Priority:** P1
**Monetization:** ઊંચી — refurb business આખો ખૂલે.
**Scalability:** સિરિયલ પ્રતિ એક કૉલમ.
**Example:** Dell 3520 — New ₹42,000 / Open Box ₹39,000 / Demo ₹36,000.

### #9 · Part Number / OEM Cross-reference 🔴
**Category:** Inventory
**શું છે?** કંપનીનો part number (MPN) અને એના બીજા નામ — એક આઇટમ સામે ઘણા કોડ.
**શું કામ કરે છે?** `item_part_numbers` (many-to-one); સર્ચ MPN થી પણ ચાલે.
**Real-world Use Case:** ગ્રાહક "CE285A" કહે, તમારા ચોપડે "HP 85A કારતૂસ" લખેલું છે.
**Customer:** સાચો પાર્ટ મળે.
**Staff:** સપ્લાયરની યાદીમાંથી સીધું match.
**Owner:** ખોટો પાર્ટ મંગાવવાનું બંધ; રિટર્ન ઘટે.
**Revenue Impact:** મધ્યમ.
**Cost Saving:** ઊંચી (ખોટી ખરીદી + રિટર્ન ખર્ચ).
**Automation:** સપ્લાયરની Excel import કરો એટલે MPN આપોઆપ મળે.
**AI Opportunity:** સપ્લાયર PDF/Excel માંથી MPN વાંચે (`bill_scan.php` નું વિસ્તરણ).
**WhatsApp:** ગ્રાહક MPN લખે → બોટ જવાબ આપે.
**Mobile/PWA:** સર્ચમાં MPN.
**DB Tables:** `item_part_numbers` (નવું).
**APIs:** `?r=items&q=` માં MPN સર્ચ.
**Security:** `items.edit`.
**Complexity:** Easy
**Priority:** P1
**Monetization:** core.
**Scalability:** index પર સર્ચ.
**Example:** "CE285A" ટાઇપ → "HP 85A Toner — 4 નંગ, રેક 2A".

### #10 · Serial-level Purchase Cost (true FIFO per piece) 🟡
**Category:** Inventory
**શું છે?** **દરેક પીસ** કેટલામાં આવ્યો એ — સરેરાશ નહીં.
**શું કામ કરે છે?** `stock_cost_layers` છે; એને `item_serials.purchase_cost` સાથે જોડવું, અને વેચાણમાં એ જ પીસનો cost વપરાય.
**Real-world Use Case:** એ જ મોડેલના 3 કેમેરા ₹900, ₹950, ₹1,020 માં આવ્યા — કયો વેચાયો એ પ્રમાણે નફો.
**Customer:** લાગુ નથી.
**Staff:** લાગુ નથી (પડદા પાછળ).
**Owner:** **નફો ખરેખર સાચો** — અત્યારે સરેરાશથી થોડું ખોટું આવે.
**Revenue Impact:** પરોક્ષ (સાચા આંકડે સાચા નિર્ણય).
**Cost Saving:** મધ્યમ.
**Automation:** સિરિયલ સ્કેન થતાં જ cost જોડાય.
**AI Opportunity:** નથી — આ deterministic હોવું જ જોઈએ.
**WhatsApp:** લાગુ નથી.
**Mobile/PWA:** લાગુ નથી.
**DB Tables:** `item_serials.purchase_cost`, `purchase_id` (કૉલમ).
**APIs:** કોઈ નવું નહીં.
**Security:** cost જોવું = `items.cost` પરમિશન (હાલની).
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Accurate Profit" પેકેજ.
**Scalability:** સિરિયલ પ્રતિ એક join.
**Example:** ₹1,020 વાળો પીસ ₹1,400 માં વેચાયો → નફો ₹380, સરેરાશથી ₹423 નહીં.

### #11 · Auto Reorder Point per Item (data-driven, not guessed) 🟡
**Category:** Inventory
**શું છે?** `min_stock` હાથે ભરવાને બદલે **વેચાણની ગતિ + ડિલિવરીના દિવસ** પરથી નક્કી થાય.
**શું કામ કરે છે?** `purchase_intel` માં ગણતરી છે; એને `items.min_stock` પર આપોઆપ લગાડવું, માલિકની મંજૂરીથી.
**Real-world Use Case:** 3,000 SKU નું min_stock કોઈ હાથે ભરી શકતું નથી.
**Customer:** માલ ખૂટે નહીં.
**Staff:** low-stock alert ખરેખર કામનું બને (અત્યારે ઘણું noise).
**Owner:** Stock-out ↓ **અને** overstock ↓ — બંને સાથે.
**Revenue Impact:** ઊંચી (ખૂટવાથી ગુમાવેલું વેચાણ બંધ).
**Cost Saving:** ઊંચી (વધારે માલમાં પૈસા ન રોકાય).
**Automation:** મહિને એક વાર બધા min_stock ની દરખાસ્ત, એક ક્લિકે મંજૂરી.
**AI Opportunity:** સીઝન + trend જોડીને — `forecast.php` નું reuse.
**WhatsApp:** "23 આઇટમના reorder level બદલવા સૂચવ્યા — જોવું?"
**Mobile/PWA:** મંજૂરી ફોનથી.
**DB Tables:** `reorder_suggestions` (નવું) + `items.min_stock`.
**APIs:** `GET ?r=reorder_suggestions`, `POST ?r=reorder_apply`.
**Security:** લાગુ કરવું = `items.edit`; દરખાસ્ત જોવું = `stock.view`.
**Complexity:** Medium
**Priority:** P0
**Monetization:** "Smart Inventory" પેકેજ.
**Scalability:** રાત્રે cron માં ગણાય.
**Example:** રોજ 1.4 નંગ વેચાય + 7 દિવસ ડિલિવરી + 3 દિવસ બફર → min_stock 14.

### #12 · Stock Take by Phone Camera (scan-count-close) 🟡
**Category:** Inventory
**શું છે?** ફોનના કેમેરાથી બારકોડ સ્કેન કરીને આખું ઑડિટ.
**શું કામ કરે છે?** `stock_counts` છે; મોબાઇલ એપમાં scan → count → post ઉમેરવું (એપમાં scanner પહેલેથી છે).
**Real-world Use Case:** વર્ષના અંતે 3,000 SKU ગણવા — કાગળ પર અશક્ય.
**Customer:** લાગુ નથી.
**Staff:** બે માણસ, બે ફોન, સાથે ગણે.
**Owner:** ઑડિટ 3 દિવસને બદલે 3 કલાક.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** ઊંચી (સ્ટાફના દિવસો).
**Automation:** ફરક હોય એની જ યાદી; ₹ ફરક સાથે.
**AI Opportunity:** કયા આઇટમમાં વારંવાર ફરક — ચોરી/ભૂલનો pattern.
**WhatsApp:** "ઑડિટ પૂરું: 3,012 માંથી 41 માં ફરક, ₹18,400."
**Mobile/PWA:** આ ફીચરનું ઘર જ મોબાઇલ છે.
**DB Tables:** કોઈ નવું નહીં.
**APIs:** `POST ?r=count_scan`, `POST ?r=count_post`.
**Security:** post કરવું = `stock_audit.add`; ફરી ખોલવું = admin.
**Complexity:** Medium
**Priority:** P1
**Monetization:** core.
**Scalability:** batch POST.
**Example:** 2 કલાકમાં 1,800 સ્કેન, 23 ફરક, એક ક્લિકે post.

### #13 · Compatible-Alternative Suggestion on Stock-out 🔴
**Category:** Inventory
**શું છે?** "એ નથી" ને બદલે **"આ ચાલશે"**.
**શું કામ કરે છે?** આઇટમ સામે equivalent group; સ્ટોક 0 હોય તો એ જ ગ્રૂપનો available પીસ સૂચવે.
**Real-world Use Case:** 8GB DDR4 3200 ખલાસ — 8GB DDR4 2666 પડ્યું છે, એ જ કામ કરશે.
**Customer:** ખાલી હાથે પાછો ન જાય.
**Staff:** નવા સ્ટાફને પણ ખબર પડે શું બદલી શકાય.
**Owner:** **ખૂટવાથી ગુમાવેલું વેચાણ સીધું બચે**; સાથે dead stock ખાલી થાય.
**Revenue Impact:** **સૌથી ઊંચી** — ના પાડેલું વેચાણ પાછું.
**Cost Saving:** dead stock → રોકડ.
**Automation:** સ્ટોક 0 થાય એટલે યાદી તૈયાર.
**AI Opportunity:** ઊંચી — spec પરથી equivalence સૂચવે, **માણસ મંજૂર કરે પછી જ** ગ્રૂપ બને.
**WhatsApp:** બોટ "એ નથી, પણ આ છે — ₹X".
**Mobile/PWA:** બિલ બનાવતી વખતે "સ્ટોક 0 — વિકલ્પ: …".
**DB Tables:** `item_equivalents` (નવું, `approved_by` સાથે).
**APIs:** `GET ?r=alternatives&item_id=`.
**Security:** ગ્રૂપ બનાવવું = `items.edit`; AI ની દરખાસ્ત approve વગર live નહીં.
**Complexity:** Medium
**Priority:** P0
**Monetization:** "Smart Inventory".
**Scalability:** સાદો lookup.
**Example:** "DDR4 3200 8GB નથી → DDR4 2666 8GB છે (₹1,450, 6 નંગ) — ચાલશે."

### #14 · Overstock & Cash-locked Alert (with a number) 🟡
**Category:** Inventory
**શું છે?** ફક્ત "વધારે સ્ટોક" નહીં — **કેટલા મહિના ચાલશે અને કેટલા ₹ રોકાયા**.
**શું કામ કરે છે?** velocity ÷ qty = months of cover; 6 મહિનાથી વધુ = overstock, ₹ સાથે.
**Real-world Use Case:** 200 માઉસ પડ્યા છે, મહિને 8 વેચાય — 25 મહિનાનો સ્ટોક.
**Customer:** લાગુ નથી.
**Staff:** ખરીદી કરતાં પહેલાં ચેતવણી.
**Owner:** **પૈસા ક્યાં દબાયા છે** એ એક આંકડામાં.
**Revenue Impact:** પરોક્ષ (રોકડ છૂટે → નવો માલ).
**Cost Saving:** ઊંચી.
**Automation:** ખરીદી નાખતી વખતે "આ પહેલેથી 25 મહિનાનો છે" warning.
**AI Opportunity:** સીઝન ધ્યાનમાં લે (દિવાળી પહેલાં વધુ સ્ટોક ખોટો નથી).
**WhatsApp:** મહિને એક વાર top-10 overstock.
**Mobile/PWA:** રિપોર્ટ ફોનમાં.
**DB Tables:** કોઈ નવું નહીં.
**APIs:** `GET ?r=overstock`.
**Security:** `reports.profit`.
**Complexity:** Easy
**Priority:** P1
**Monetization:** "Smart Inventory".
**Scalability:** હાલની `dash_stock` ગણતરી.
**Example:** "માઉસ: 200 નંગ = 25 મહિનાનો સ્ટોક, ₹38,000 રોકાયા."

---
## CATEGORY 3 — PURCHASE (5)

### #15 · Purchase Order (PO) before the bill 🔴
**Category:** Purchase
**શું છે?** સપ્લાયરને **ઓર્ડર** આપો, માલ પછી આવે — અત્યારે ફક્ત purchase **bill** છે.
**શું કામ કરે છે?** `purchase_orders` + `purchase_order_items`; માલ આવે એટલે PO → purchase bill (ભાગમાં પણ).
**Real-world Use Case:** દર અઠવાડિયે distributor ને 30 આઇટમનો ઓર્ડર જાય; માલ 2 વારમાં આવે.
**Customer:** પરોક્ષ (માલ સમયસર).
**Staff:** શું મંગાવેલું અને શું આવ્યું — યાદી સામે ટિક.
**Owner:** "મંગાવેલું પણ આવ્યું નથી" એ pipeline દેખાય; ભાવ PO વખતે lock.
**Revenue Impact:** ઊંચી (ખૂટવાનું ઘટે).
**Cost Saving:** ઊંચી (ભાવ lock, ખોટી ડિલિવરી પકડાય).
**Automation:** PO આપોઆપ reorder-suggestion માંથી બને; PDF/WhatsApp સપ્લાયરને.
**AI Opportunity:** કયા સપ્લાયરને કયો ઓર્ડર — ભાવ + ડિલિવરી ઇતિહાસ પરથી.
**WhatsApp:** PO PDF સપ્લાયરને; ડિલિવરી મોડી થાય તો રિમાઇન્ડર.
**Mobile/PWA:** ફોનથી PO મંજૂર.
**DB Tables:** `purchase_orders`, `purchase_order_items`, `purchases.po_id`.
**APIs:** `POST ?r=po`, `GET ?r=po_pending`, `POST ?r=po_receive`.
**Security:** `purchases.add`; મંજૂરી હદથી મોટા PO પર (expense_requests નું pattern).
**Complexity:** Medium
**Priority:** P0
**Monetization:** core.
**Scalability:** sales જેવું જ schema.
**Example:** PO-26-27-0012, 30 આઇટમ ₹4.2L → 18 આઇટમ આવ્યાં → બાકી 12 pending.

### #16 · Goods Receipt Note (GRN) — what arrived vs what was ordered 🔴
**Category:** Purchase
**શું છે?** માલ આવે ત્યારે **ગણીને** લેવો; ખૂટતું/તૂટેલું નોંધવું.
**શું કામ કરે છે?** PO સામે receipt; short/damaged qty અલગ; stock ફક્ત સ્વીકારેલા પર ચડે.
**Real-world Use Case:** 20 કેમેરા મંગાવ્યા, 18 આવ્યા, 1 તૂટેલો — બિલ 20 નું આવ્યું છે.
**Customer:** પરોક્ષ.
**Staff:** ગણતરી વખતે જ નોંધ; પછી યાદ રાખવાનું નહીં.
**Owner:** સપ્લાયર સામે **પુરાવો**; ખોટું બિલ ચૂકવાય નહીં.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** **ઊંચી** — short delivery ના પૈસા બચે.
**Automation:** ફરક હોય તો સપ્લાયરને WhatsApp + credit note માગે.
**AI Opportunity:** કયો સપ્લાયર વારંવાર short મોકલે — performance score.
**WhatsApp:** "PO-12: 2 નંગ ખૂટે, 1 તૂટેલો — ₹4,600 ની credit note જોઈશે."
**Mobile/PWA:** ગોડાઉનમાં ફોનથી GRN.
**DB Tables:** `goods_receipts`, `goods_receipt_items`.
**APIs:** `POST ?r=grn`.
**Security:** `purchases.add`; GRN બદલવું admin.
**Complexity:** Medium
**Priority:** P1
**Monetization:** core.
**Scalability:** સાદું.
**Example:** ઓર્ડર 20 / આવ્યા 18 / તૂટેલા 1 / સ્વીકાર્યા 17 → સ્ટોક 17 ચડ્યો.

### #17 · Landed Cost (freight, GST, octroi spread per item) 🔴
**Category:** Purchase
**શું છે?** માલની **સાચી પડતર** — ભાવ + ભાડું + બીજા ખર્ચ વહેંચીને.
**શું કામ કરે છે?** purchase bill પર extra ખર્ચ; qty કે value પ્રમાણે લાઇનમાં વહેંચાય; એ જ cost નફામાં વપરાય.
**Real-world Use Case:** ₹80,000 ના માલ પર ₹1,800 ટ્રાન્સપોર્ટ — એ પડતરમાં ગણાવું જોઈએ.
**Customer:** લાગુ નથી.
**Staff:** લાગુ નથી.
**Owner:** **નફો ખરેખર સાચો**; અત્યારે ભાડું ખર્ચમાં જાય અને માર્જિન વધારે દેખાય.
**Revenue Impact:** પરોક્ષ (ભાવ સાચા આવે).
**Cost Saving:** મધ્યમ.
**Automation:** વહેંચણી આપોઆપ; કઈ રીતે વહેંચવું એ સેટિંગ.
**AI Opportunity:** નથી — આ ગણિત છે, deterministic રહેવું જોઈએ.
**WhatsApp:** લાગુ નથી.
**Mobile/PWA:** લાગુ નથી.
**DB Tables:** `purchase_charges` (નવું); `purchase_items.landed_cost`.
**APIs:** કોઈ નવું નહીં.
**Security:** `purchases.edit`; cost જોવું `items.cost`.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Accurate Profit".
**Scalability:** બિલ પ્રતિ ગણતરી.
**Example:** કેમેરાની પડતર ₹900 → landed ₹920 → માર્જિન 2% ઓછું, પણ **સાચું**.

### #18 · Purchase Suggestion → PO in one click 🟡
**Category:** Purchase
**શું છે?** "શું મંગાવવું" ની યાદીમાંથી **સીધો ઓર્ડર** બને.
**શું કામ કરે છે?** `purchase_intel.php` ની યાદીમાં ટિક → સપ્લાયર પ્રમાણે PO ભાગ પડે → PDF/WhatsApp.
**Real-world Use Case:** સોમવારે સવારે યાદી જુઓ, ટિક કરો, 3 સપ્લાયરને 3 PO ગયા.
**Customer:** પરોક્ષ.
**Staff:** ટાઇપિંગ ખતમ.
**Owner:** ખરીદીનું કામ 1 કલાકથી 10 મિનિટ.
**Revenue Impact:** ઊંચી (સમયસર ખરીદી).
**Cost Saving:** ઊંચી (સ્ટાફનો સમય).
**Automation:** સંપૂર્ણ — cron સૂચવે, માણસ ટિક કરે.
**AI Opportunity:** કયા સપ્લાયર પાસે કઈ લાઇન — ભાવ ઇતિહાસ પરથી ભાગ પાડે.
**WhatsApp:** દરેક સપ્લાયરને એનો PO.
**Mobile/PWA:** ફોનથી ટિક કરીને મોકલો.
**DB Tables:** #15 નાં જ.
**APIs:** `POST ?r=po_from_suggestions`.
**Security:** `purchases.add`.
**Complexity:** Easy (યાદી પહેલેથી છે)
**Priority:** P0
**Monetization:** "Smart Inventory".
**Scalability:** સાદું.
**Example:** 41 સૂચન → 3 PO (₹1.8L, ₹64,000, ₹22,000) → WhatsApp.

### #19 · Import Supplier Price List (Excel/PDF) 🟡
**Category:** Purchase
**શું છે?** સપ્લાયરની ભાવયાદી import કરીને **તમારા ભાવ સામે સરખાવો**.
**શું કામ કરે છે?** Excel/CSV upload; MPN કે નામથી match; નવો ભાવ સૂચવે — મંજૂરી પછી લાગુ.
**Real-world Use Case:** distributor મહિને નવી rate list મોકલે; 400 લાઇન હાથે ચેક કરવી અશક્ય.
**Customer:** સાચા ભાવ.
**Staff:** ટાઇપિંગ નહીં.
**Owner:** ભાવ વધ્યા/ઘટ્યા એ **તરત** ખબર; વેચાણ ભાવ સમયસર સુધરે.
**Revenue Impact:** **ઊંચી** — જૂના ભાવે વેચીને નુકસાન બંધ.
**Cost Saving:** ઊંચી.
**Automation:** upload → સૂચન → એક ક્લિકે મંજૂરી.
**AI Opportunity:** PDF rate list વાંચે (`bill_scan.php` reuse); નામ match કરે.
**WhatsApp:** "Supplier A ની નવી યાદી: 23 આઇટમના ભાવ વધ્યા."
**Mobile/PWA:** મંજૂરી ફોનથી.
**DB Tables:** `supplier_price_lists`, `supplier_prices` (નવાં).
**APIs:** `POST ?r=price_list_import`.
**Security:** `purchases.edit`; ભાવ લાગુ કરવું `items.edit` + audit.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Purchase Pro".
**Scalability:** batch insert.
**Example:** 412 લાઇન → 23 ભાવ વધ્યા, 8 ઘટ્યા → મંજૂરી → વેચાણ ભાવ આપોઆપ સુધર્યા.

---

## CATEGORY 4 — SUPPLIER (4)

### #20 · Best Supplier / Price Comparison 🔴
**Category:** Supplier
**શું છે?** એક જ આઇટમ કયા સપ્લાયર પાસે **કેટલામાં** — સાથે.
**શું કામ કરે છે?** `supplier_prices` + ખરીદી ઇતિહાસ પરથી પ્રતિ-આઇટમ સરખામણી; ભાવ + ડિલિવરી + વિશ્વસનીયતા.
**Real-world Use Case:** "આ કેમેરા A પાસે ₹900, B પાસે ₹880 પણ B 12 દિવસ લે છે."
**Customer:** સસ્તો ભાવ મળી શકે.
**Staff:** કોને ફોન કરવો એ સ્પષ્ટ.
**Owner:** **દર ખરીદીએ બચત**; bargaining માટે આંકડો હાથમાં.
**Revenue Impact:** પરોક્ષ ઊંચી (માર્જિન ↑).
**Cost Saving:** **સૌથી ઊંચી** — 2-5% ખરીદી પર.
**Automation:** PO બનતી વખતે સૌથી સસ્તો સૂચવે.
**AI Opportunity:** ભાવ + ડિલિવરી + short-supply ઇતિહાસનું score.
**WhatsApp:** "આ ઓર્ડર B પાસેથી લો તો ₹4,800 બચે (પણ 5 દિવસ વધુ)."
**Mobile/PWA:** ખરીદી કરતી વખતે ફોનમાં.
**DB Tables:** `supplier_prices` (#19 નું), `supplier_scores` (નવું).
**APIs:** `GET ?r=supplier_compare&item_id=`.
**Security:** `purchases.view` + `items.cost`.
**Complexity:** Medium
**Priority:** P0
**Monetization:** "Purchase Pro" — સૌથી સહેલું વેચાય.
**Scalability:** aggregate + cache.
**Example:** "HP 85A — A: ₹1,180 (3 દિ) · B: ₹1,140 (11 દિ) · C: ₹1,205 (આજે)".

### #21 · Supplier Performance Score 🔴
**Category:** Supplier
**શું છે?** સપ્લાયર **કેટલો ભરોસાપાત્ર** — આંકડામાં.
**શું કામ કરે છે?** GRN ડેટા પરથી: સમયસર %, short-supply %, damaged %, ભાવ સ્થિરતા.
**Real-world Use Case:** "B સસ્તો છે પણ 30% વાર માલ ખૂટતો મોકલે."
**Customer:** પરોક્ષ (માલ સમયસર).
**Staff:** કોના પર ભરોસો.
**Owner:** સસ્તા ભાવની **છુપી કિંમત** દેખાય.
**Revenue Impact:** મધ્યમ.
**Cost Saving:** ઊંચી.
**Automation:** GRN દરેક વખતે score અપડેટ.
**AI Opportunity:** નથી જરૂરી — સાદું ગણિત વધુ ભરોસાપાત્ર.
**WhatsApp:** ત્રિમાસિક સપ્લાયર report.
**Mobile/PWA:** જોવા પૂરતું.
**DB Tables:** `supplier_scores`.
**APIs:** `GET ?r=supplier_score`.
**Security:** `purchases.view`.
**Complexity:** Easy
**Priority:** P2
**Monetization:** "Purchase Pro".
**Scalability:** રાત્રે ગણાય.
**Example:** "Supplier B — સમયસર 68%, short 12%, ભાવ સ્થિર 91%".

### #22 · Supplier RMA / Warranty Pipeline 🟡
**Category:** Supplier
**શું છે?** ખરાબ માલ **કંપનીને પાછો** — અને પાછો આવે ત્યાં સુધીનો પીછો.
**શું કામ કરે છે?** `warranty_claims` છે; એને supplier-side dispatch/receive/credit સાથે પૂરું કરવું; pending RMA ની ઉંમર દેખાય.
**Real-world Use Case:** 6 ખરાબ SMPS કંપનીને ગયા, 2 મહિના થયા, કંઈ પાછું નથી આવ્યું.
**Customer:** replacement ઝડપી મળે.
**Staff:** કયો પીસ ક્યાં છે એ સ્પષ્ટ.
**Owner:** **RMA માં ફસાયેલા ₹** દેખાય; કંપની પાસે ઉઘરાણી થાય.
**Revenue Impact:** મધ્યમ.
**Cost Saving:** **ઊંચી** — ભૂલાયેલા RMA = સીધું નુકસાન.
**Automation:** 30 દિવસ થાય એટલે કંપનીને રિમાઇન્ડર.
**AI Opportunity:** કયા મોડેલમાં વારંવાર ખરાબી — ખરીદી બંધ કરવાનો સંકેત.
**WhatsApp:** કંપનીને "RMA-14: 45 દિવસ થયા".
**Mobile/PWA:** pending RMA યાદી.
**DB Tables:** `warranty_claims` + `rma_shipments` (નવું).
**APIs:** `POST ?r=rma_dispatch`, `POST ?r=rma_receive`.
**Security:** `warranty.edit`.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Service Pro".
**Scalability:** સાદું.
**Example:** "RMA માં ₹38,400 ફસાયા — 6 પીસ, સૌથી જૂનો 71 દિવસ."

### #23 · Supplier Credit Terms & Due Planner 🟡
**Category:** Supplier
**શું છે?** કયા સપ્લાયરને **ક્યારે** ચૂકવવાનું — અઠવાડિયા પ્રમાણે.
**શું કામ કરે છે?** `credit_terms` છે; એને forecast સાથે જોડીને "આ અઠવાડિયે ₹X ચૂકવવાના" દેખાડવું.
**Real-world Use Case:** 3 સપ્લાયરની મુદત એક જ અઠવાડિયે પડે — રોકડ ખેંચાય.
**Customer:** લાગુ નથી.
**Staff:** લાગુ નથી.
**Owner:** **રોકડનું આગોતરું ચિત્ર**; મુદત વટાવવાનું બંધ.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** મધ્યમ (late fee, સંબંધ).
**Automation:** મુદતના 3 દિવસ પહેલાં યાદ.
**AI Opportunity:** રોકડ ખેંચ દેખાય તો કઈ ચૂકવણી પહેલાં — priority સૂચન.
**WhatsApp:** "આ અઠવાડિયે ₹1.4L ચૂકવવાના — 3 સપ્લાયર."
**Mobile/PWA:** હા.
**DB Tables:** કોઈ નવું નહીં.
**APIs:** `GET ?r=payables_plan`.
**Security:** `payments.view`.
**Complexity:** Easy
**Priority:** P1
**Monetization:** core.
**Scalability:** `forecast.php` reuse.
**Example:** "અઠવાડિયું 41: ₹1,42,000 — A ₹86,000 (12 તા.), B ₹34,000 (14 તા.)".

---

## CATEGORY 5 — CUSTOMER / CRM (5)

### #24 · Customer Device Registry (કોના ઘરે શું છે) 🔴
**Category:** Customer/CRM
**શું છે?** દરેક ગ્રાહકનાં **ઉપકરણ** — કયું મોડેલ, કયો સિરિયલ, ક્યારે લીધું, વોરંટી ક્યારે પૂરી.
**શું કામ કરે છે?** વેચાણના સિરિયલ પરથી આપોઆપ બને; રિપેર/AMC એની સાથે જોડાય.
**Real-world Use Case:** ફોન આવે "મારું પ્રિન્ટર ચાલતું નથી" — કયું પ્રિન્ટર છે એ પૂછવું ન પડે.
**Customer:** વારંવાર વિગત આપવી ન પડે; "મારાં ઉપકરણ" પોર્ટલમાં દેખાય.
**Staff:** ફોન ઉપાડતાં જ આખો ઇતિહાસ સામે.
**Owner:** **upgrade/AMC વેચવાનો સૌથી મોટો રસ્તો** — કોની પાસે 4 વર્ષનું લેપટોપ છે એ ખબર.
**Revenue Impact:** **ઊંચી** (targeted upgrade offer).
**Cost Saving:** સપોર્ટનો સમય ઘટે.
**Automation:** સિરિયલ વેચાય એટલે registry માં ઉમેરાય.
**AI Opportunity:** "આ ગ્રાહકનું લેપટોપ 4 વર્ષનું — SSD upgrade સૂચવો".
**WhatsApp:** "તમારા HP પ્રિન્ટરની વોરંટી 30 દિવસમાં પૂરી."
**Mobile/PWA:** ગ્રાહક પોર્ટલમાં "મારાં ઉપકરણ".
**DB Tables:** `customer_devices` (નવું; `item_serials` સાથે જોડાણ).
**APIs:** `GET ?r=customer_devices&party_id=`.
**Security:** ગ્રાહક ફક્ત પોતાનું; સ્ટાફ `parties.view`.
**Complexity:** Medium
**Priority:** P0
**Monetization:** ઊંચી — AMC/upgrade વેચાણનો પાયો.
**Scalability:** સિરિયલ પર index.
**Example:** "રમેશભાઈ — Dell 3520 (2022), HP 1020 (2019), 4 CCTV (2024)".

### #25 · Repeat-purchase Cycle Reminder 🔴
**Category:** Customer/CRM
**શું છે?** જે વસ્તુ **ફરી ફરી** જોઈએ (કારતૂસ, HDD, ribbon) એનું આગોતરું રિમાઇન્ડર.
**શું કામ કરે છે?** ગ્રાહક + આઇટમ પ્રતિ સરેરાશ અંતર ગણે; ચક્ર પૂરું થાય એ પહેલાં યાદ કરાવે.
**Real-world Use Case:** દર 70 દિવસે કારતૂસ લે છે — 60મા દિવસે મેસેજ.
**Customer:** ખૂટે એ પહેલાં મળે.
**Staff:** ફોન કરવાની યાદી તૈયાર.
**Owner:** **પુનરાવર્તિત આવક** — ગ્રાહક બીજે ન જાય.
**Revenue Impact:** **સૌથી ઊંચી** ROI (હાલના ગ્રાહકને ફરી વેચવું સૌથી સસ્તું).
**Cost Saving:** માર્કેટિંગ ખર્ચ નહીં.
**Automation:** સંપૂર્ણ — cron + WhatsApp.
**AI Opportunity:** ચક્રની લંબાઈ અને best time — પણ `campaign.php` ના consent નિયમો પહેલાં.
**WhatsApp:** "કારતૂસ ખૂટવા આવ્યું હશે? ₹1,180 — કહો તો રાખી દઈએ."
**Mobile/PWA:** આજની ફોન-યાદી.
**DB Tables:** `purchase_cycles` (નવું, ગણેલું).
**APIs:** `GET ?r=due_repeat`.
**Security:** `campaigns` ના opt-out નિયમ લાગુ **જ** પડે.
**Complexity:** Medium
**Priority:** P0
**Monetization:** "Marketing Pro".
**Scalability:** રાત્રે ગણાય.
**Example:** 34 ગ્રાહકનું ચક્ર આ અઠવાડિયે પૂરું → 34 મેસેજ → 11 ઓર્ડર.

### #26 · Customer Health / Churn Risk 🟡
**Category:** Customer/CRM
**શું છે?** કયો ગ્રાહક **છૂટી રહ્યો છે** — છોડી જાય એ પહેલાં.
**શું કામ કરે છે?** `customer.php` માં "છૂટી રહ્યા" છે; એને score + કારણ + action સાથે પૂરું કરવું.
**Real-world Use Case:** જે દર મહિને આવતો હતો એ 3 મહિના નથી આવ્યો.
**Customer:** ધ્યાન મળે; ઓફર મળે.
**Staff:** કોને ફોન કરવો — ક્રમમાં.
**Owner:** **જૂનો ગ્રાહક બચાવવો નવો શોધવા કરતાં સસ્તો**.
**Revenue Impact:** ઊંચી.
**Cost Saving:** ઊંચી.
**Automation:** અઠવાડિયે યાદી; મેસેજ માણસની મંજૂરીથી.
**AI Opportunity:** કારણ સૂચવે (ભાવ? સર્વિસ? હરીફ?) — પણ **ચોપડો કારણ જાણતો નથી** એ પ્રામાણિકપણે લખવું.
**WhatsApp:** win-back મેસેજ (પહેલેથી `campaigns` માં છે).
**Mobile/PWA:** હા.
**DB Tables:** કોઈ નવું નહીં.
**APIs:** `GET ?r=churn_risk`.
**Security:** `parties.view`.
**Complexity:** Easy
**Priority:** P1
**Monetization:** "Marketing Pro".
**Scalability:** cache.
**Example:** "12 ગ્રાહક જોખમમાં, ₹2.4L/વર્ષ — સૌથી ઉપર: કિરણભાઈ (₹48,000/વર્ષ, 94 દિવસ ગેરહાજર)".

### #27 · Corporate Contact Persons (એક કંપની, ઘણા માણસ) 🔴
**Category:** Customer/CRM
**શું છે?** એક પાર્ટીની નીચે **ઘણા સંપર્ક** — purchase, accounts, IT.
**શું કામ કરે છે?** `party_contacts`; બિલ/રિમાઇન્ડર કયા માણસને એ નક્કી.
**Real-world Use Case:** હોટેલનું બિલ accountant ને, રિપેરની જાણ manager ને.
**Customer:** સાચા માણસને સાચી વાત.
**Staff:** કોને ફોન કરવો એ સ્પષ્ટ.
**Owner:** corporate વેચાણ professional લાગે; ઉઘરાણી accounts ને જ જાય.
**Revenue Impact:** મધ્યમ (corporate વિશ્વાસ).
**Cost Saving:** ઓછો ગૂંચવાડો.
**Automation:** ભૂમિકા પ્રમાણે મેસેજ routing.
**AI Opportunity:** નથી.
**WhatsApp:** ઉઘરાણી → accounts; રિપેર → IT.
**Mobile/PWA:** સંપર્ક યાદી.
**DB Tables:** `party_contacts` (નવું).
**APIs:** `GET/POST ?r=party_contacts`.
**Security:** `parties.edit`; નંબર consent સાથે.
**Complexity:** Easy
**Priority:** P2
**Monetization:** "B2B" પેકેજ.
**Scalability:** સાદું.
**Example:** "Hotel Sagar — Purchase: મહેશ, Accounts: નીતા, IT: રાહુલ".

### #28 · Feedback → Google Review funnel (with a gate) 🟡
**Category:** Customer/CRM
**શું છે?** પહેલાં **ખાનગીમાં** પૂછો; ખુશ હોય તો જ Google review માગો.
**શું કામ કરે છે?** `feedback` છે; 4-5 star → review link, 1-3 star → માલિકને તરત જાણ.
**Real-world Use Case:** નારાજ ગ્રાહકને review link મોકલવો = જાહેરમાં નુકસાન.
**Customer:** ફરિયાદ સીધી માલિક સુધી; સંતોષ થાય.
**Staff:** નારાજગી પહેલાં પકડાય.
**Owner:** **Google rating વધે**, ખરાબ review પહેલાં ઉકેલ.
**Revenue Impact:** ઊંચી (rating = નવા ગ્રાહક).
**Cost Saving:** reputation ખર્ચ બચે.
**Automation:** બિલ/રિપેર પછી આપોઆપ.
**AI Opportunity:** ફરિયાદનો સારાંશ + સૂચવેલો જવાબ (માણસ મોકલે).
**WhatsApp:** 1-5 star બટન; પછી link કે માફી.
**Mobile/PWA:** માલિકને alert.
**DB Tables:** કોઈ નવું નહીં.
**APIs:** `POST ?r=feedback`.
**Security:** **ક્યારેય નકલી review નહીં** — ફક્ત અસલી ગ્રાહકને, બિલ સામે.
**Complexity:** Easy
**Priority:** P1
**Monetization:** "Marketing Pro".
**Scalability:** સાદું.
**Example:** 5⭐ → "Google પર લખશો?" · 2⭐ → માલિકને "કિરણભાઈ નારાજ — ફોન કરો".

---
## CATEGORY 6 — QUOTATION (5)

### #29 · PC Builder → Quotation (the module) 🔴
**Category:** Quotation · *PC Builder module ભાગ 1/5*
**શું છે?** બજેટ નાખો → **પૂરું configuration** બને (CPU, MB, RAM, GPU, SSD, PSU, Cabinet, Cooler, Monitor) → સીધું ક્વોટેશન.
**શું કામ કરે છે?** `item_specs` માં spec ભરેલા હોય; builder બજેટ મુજબ ભાગ પાડે (CPU ~25%, GPU ~30%…), સ્ટોકમાં હોય એને પહેલાં રાખે, પછી `estimates` બનાવે.
**Real-world Use Case:** "₹70,000 નું Gaming PC જોઈએ" — અત્યારે આ કામ કાગળ પર 30 મિનિટ લે છે.
**Customer:** તરત જવાબ, લખેલું configuration, ભાવ સાથે.
**Staff:** technician ની જરૂર વગર સેલ્સમેન પણ બનાવી શકે.
**Owner:** **સૌથી મોટી ટિકિટનું વેચાણ ઝડપી**; સ્ટોકમાં પડેલા પાર્ટ વપરાય.
**Revenue Impact:** **સૌથી ઊંચી** — assembled PC = મોટું બિલ + ઊંચું માર્જિન.
**Cost Saving:** સમય; ખોટા combination ની ભૂલ.
**Automation:** સ્ટોકમાં જે છે એમાંથી પહેલાં બનાવે.
**AI Opportunity:** #63 જુઓ (AI PC Builder) — પણ **ભાવ અને compatibility કોડ નક્કી કરે, AI નહીં**.
**WhatsApp:** configuration + ભાવ PDF ગ્રાહકને.
**Mobile/PWA:** ગ્રાહક જાતે વેબસાઈટ પર બનાવે.
**DB Tables:** `item_specs`, `build_templates`, `builds`, `build_items` (નવાં).
**APIs:** `POST ?r=build`, `GET ?r=build&id=`.
**Security:** ભાવ `dealer_price()` થી જ; margin floor લાગુ.
**Complexity:** Hard
**Priority:** P0
**Monetization:** **સૌથી વધુ** — આ એકલું SaaS વેચી શકાય.
**Scalability:** spec ટેબલ પર index; build cache.
**Example:** ₹70,000 → Ryzen 5 7600 + B650 + 16GB + RTX 4060 + 1TB NVMe + 650W + Cabinet = ₹69,400 (સ્ટોકમાં 7/8 પાર્ટ).

### #30 · Component Compatibility Engine 🔴
**Category:** Quotation · *PC Builder module ભાગ 2/5*
**શું છે?** પાર્ટ **સાથે ચાલશે કે નહીં** — સોકેટ, RAM પ્રકાર, cabinet માપ, GPU લંબાઈ.
**શું કામ કરે છે?** નિયમો deterministic: `cpu.socket == mb.socket`, `ram.type == mb.ram_type`, `gpu.length <= cabinet.max_gpu`, `cooler.height <= cabinet.max_cooler`.
**Real-world Use Case:** AM5 CPU સાથે AM4 મધરબોર્ડ વેચાઈ જાય તો માલ પાછો + ગ્રાહક ગુમાવ્યો.
**Customer:** ખોટો પાર્ટ ન મળે.
**Staff:** નવો સ્ટાફ પણ સલામત રીતે વેચે.
**Owner:** **રિટર્ન અને શરમ બંને બંધ**.
**Revenue Impact:** ઊંચી (રિટર્ન = ગુમાવેલું વેચાણ).
**Cost Saving:** **ઊંચી** — રિટર્ન, રિસ્ટોકિંગ, પ્રતિષ્ઠા.
**Automation:** બિલ/ક્વોટ બનાવતી વખતે લાલ ચેતવણી.
**AI Opportunity:** spec **ભરવામાં** AI (datasheet વાંચે), **ચકાસવામાં નહીં** — એ કોડનું કામ.
**WhatsApp:** લાગુ નથી.
**Mobile/PWA:** બિલ સ્ક્રીન પર warning.
**DB Tables:** `item_specs`, `compat_rules` (નવાં).
**APIs:** `POST ?r=compat_check`.
**Security:** નિયમ બદલવો = admin.
**Complexity:** Hard
**Priority:** P0
**Monetization:** PC Builder પેકેજમાં.
**Scalability:** in-memory નિયમો.
**Example:** "⚠️ Ryzen 7600 (AM5) સાથે B450 (AM4) નહીં ચાલે — B650 લો."

### #31 · PSU Wattage & Thermal Calculator 🔴
**Category:** Quotation · *PC Builder module ભાગ 3/5*
**શું છે?** આ configuration ને **કેટલા watt** નો PSU જોઈએ.
**શું કામ કરે છે?** દરેક પાર્ટનો TDP જોડે + 30% headroom → ભલામણ; ઓછો PSU હોય તો રોકે.
**Real-world Use Case:** RTX 4060 સાથે 450W PSU વેચાય → PC બંધ પડે → વોરંટી ક્લેમ.
**Customer:** PC સ્થિર ચાલે.
**Staff:** ગણતરી કરવી ન પડે.
**Owner:** **રિપેર/વોરંટીના કજિયા બંધ**; ઊંચા PSU નું ઊંચું માર્જિન પણ મળે.
**Revenue Impact:** મધ્યમ-ઊંચી (upsell).
**Cost Saving:** ઊંચી.
**Automation:** build માં આપોઆપ.
**AI Opportunity:** નથી — શુદ્ધ ગણિત.
**WhatsApp:** લાગુ નથી.
**Mobile/PWA:** હા.
**DB Tables:** `item_specs.tdp_watt`.
**APIs:** `POST ?r=psu_calc`.
**Security:** કોઈ નહીં.
**Complexity:** Easy (spec ભરાયા પછી)
**Priority:** P1
**Monetization:** PC Builder પેકેજ.
**Scalability:** સાદો સરવાળો.
**Example:** CPU 105W + GPU 115W + બાકી 60W = 280W → +30% = **650W ભલામણ**.

### #32 · Quotation Versions & Comparison 🟡
**Category:** Quotation
**શું છે?** એ જ ગ્રાહકને **3 વિકલ્પ** — Good / Better / Best, એક જ પાનામાં.
**શું કામ કરે છે?** `estimates` માં `parent_id` + `variant`; PDF માં ત્રણ કૉલમ સાથે.
**Real-world Use Case:** CCTV માં 4 કેમેરા / 8 કેમેરા / 8 + NVR upgrade.
**Customer:** સરખાવીને પસંદ કરે — વિશ્વાસ વધે.
**Staff:** એક વાર બનાવો, ત્રણ વિકલ્પ.
**Owner:** **વચલો કે ઊંચો વિકલ્પ વધુ વેચાય** (anchoring) — સીધું માર્જિન.
**Revenue Impact:** **ઊંચી** — સરેરાશ બિલ વધે.
**Cost Saving:** સમય.
**Automation:** એક variant પરથી બીજા બે સૂચવે.
**AI Opportunity:** Good/Better/Best માટે પાર્ટ સૂચવે.
**WhatsApp:** એક PDF, ત્રણ કૉલમ.
**Mobile/PWA:** ગ્રાહક link ખોલીને પસંદ કરે.
**DB Tables:** `estimates.parent_id`, `estimates.variant`.
**APIs:** `POST ?r=estimate_variant`.
**Security:** `estimates.add`.
**Complexity:** Medium
**Priority:** P1
**Monetization:** core.
**Scalability:** સાદું.
**Example:** ₹52,000 / ₹68,000 / ₹84,000 — ગ્રાહકે વચલું લીધું.

### #33 · Quote Accept/Reject online + Expiry 🟡
**Category:** Quotation
**શું છે?** ગ્રાહક **link પર જ** હા/ના કરે; ભાવ ક્યાં સુધી માન્ય એ લખેલું.
**શું કામ કરે છે?** share_token વાળું પાનું (હાલની પદ્ધતિ) + Accept/Reject બટન + `valid_until`; accept થાય એટલે sales order બને.
**Real-world Use Case:** ક્વોટ મોકલ્યા પછી 10 દિવસ કંઈ ખબર નથી પડતી.
**Customer:** ફોન કર્યા વગર હા કહી શકે.
**Staff:** કોણે હા કહી એ તરત; follow-up ફક્ત બાકીના પર.
**Owner:** **conversion માપી શકાય**; ભાવ વધ્યા પછી જૂનો ક્વોટ ન ચાલે.
**Revenue Impact:** ઊંચી (ઝડપી closing).
**Cost Saving:** follow-up કૉલ ઘટે.
**Automation:** expiry પહેલાં 2 રિમાઇન્ડર; accept થાય એટલે માલિકને જાણ.
**AI Opportunity:** ક્યારે follow-up કરવો — deterministic ladder પહેલાં (`dunning_steps()` જેવું).
**WhatsApp:** "ક્વોટ 3 દિવસમાં પૂરો થાય — હા કહેવું છે?" બટન સાથે.
**Mobile/PWA:** ગ્રાહકનું પાનું મોબાઇલ-first.
**DB Tables:** `estimates.valid_until`, `estimates.decided_at`, `estimates.decision`.
**APIs:** `POST ?r=quote_decide` (token થી, લોગિન વગર).
**Security:** ફક્ત share_token; rate-limited; નિર્ણય એક જ વાર.
**Complexity:** Medium
**Priority:** P0
**Monetization:** core.
**Scalability:** સાદું.
**Example:** 18 ક્વોટ મોકલ્યા → 7 accept, 3 reject, 8 expire → conversion 39%.

---

## CATEGORY 7 — WARRANTY (5)

### #34 · Customer Warranty Self-check (serial નાખો, જવાબ મળે) 🟡
**Category:** Warranty
**શું છે?** ગ્રાહક **સિરિયલ નાખીને** પોતે વોરંટી તપાસે — લોગિન વગર.
**શું કામ કરે છે?** જાહેર પાનું + `?r=warranty_check`; `item_serials.warranty_expiry` પરથી જવાબ; rate-limited.
**Real-world Use Case:** "મારા કેમેરાની વોરંટી છે?" — રોજના 5-10 ફોન.
**Customer:** તરત જવાબ, દુકાન બંધ હોય તો પણ.
**Staff:** ફોન ઘટે.
**Owner:** સપોર્ટનો સમય બચે; professional.
**Revenue Impact:** પરોક્ષ; વોરંટી પૂરી થઈ હોય તો **AMC/repair ઓફર** કરવાની તક.
**Cost Saving:** ઊંચી (સ્ટાફનો સમય).
**Automation:** પૂરી થતી વોરંટી પર AMC ઓફર.
**AI Opportunity:** નથી.
**WhatsApp:** બોટમાં "warranty <serial>".
**Mobile/PWA:** જાહેર પાનું.
**DB Tables:** કોઈ નવું નહીં.
**APIs:** `GET ?r=warranty_check&sn=` (public, rate-limited).
**Security:** **ફક્ત** status/તારીખ — ગ્રાહકનું નામ કે ભાવ કદી નહીં; brute-force પર throttle.
**Complexity:** Easy
**Priority:** P0
**Monetization:** core.
**Scalability:** index પર lookup.
**Example:** "SN CAM4471 — વોરંટી 2028-03-14 સુધી ✔ (18 મહિના બાકી)".

### #35 · Warranty Expiry Campaign (AMC upsell) 🟡
**Category:** Warranty
**શું છે?** વોરંટી પૂરી થાય **એ પહેલાં** AMC કે extended warranty વેચો.
**શું કામ કરે છે?** 30/7 દિવસ પહેલાં યાદી; `campaigns` થી મેસેજ (consent નિયમો સાથે).
**Real-world Use Case:** 40 CCTV ની વોરંટી આ મહિને પૂરી — AMC વેચવાની તક.
**Customer:** ધ્યાન રહે; સર્વિસ ચાલુ રહે.
**Staff:** તૈયાર યાદી.
**Owner:** **નવી આવક જૂના ગ્રાહકમાંથી** — AMC = પુનરાવર્તિત.
**Revenue Impact:** **ઊંચી** (AMC નું માર્જિન ઊંચું).
**Cost Saving:** માર્કેટિંગ નહીં.
**Automation:** સંપૂર્ણ; cron + WhatsApp.
**AI Opportunity:** કોને AMC લેવાની શક્યતા વધુ — ખરીદી/રિપેર ઇતિહાસ પરથી.
**WhatsApp:** "તમારા 8 કેમેરાની વોરંટી 12 તા. પૂરી — AMC ₹6,000/વર્ષ?"
**Mobile/PWA:** આજની ઓફર-યાદી.
**DB Tables:** કોઈ નવું નહીં (`amc_contracts` + `item_serials`).
**APIs:** `GET ?r=warranty_expiring`.
**Security:** opt-out માન્ય રાખવો **જ**.
**Complexity:** Easy
**Priority:** P0
**Monetization:** "Service Pro".
**Scalability:** cron.
**Example:** 40 વોરંટી પૂરી → 40 મેસેજ → 9 AMC (₹54,000/વર્ષ).

### #36 · Extended Warranty as a sellable product 🔴
**Category:** Warranty
**શું છે?** વોરંટી **વેચવાની વસ્તુ** — +1 વર્ષ ₹X, બિલમાં લાઇન તરીકે.
**શું કામ કરે છે?** service-type આઇટમ જે serial સાથે જોડાય અને `warranty_expiry` લંબાવે.
**Real-world Use Case:** લેપટોપ સાથે "+1 વર્ષ ₹2,500" — શૂન્ય સ્ટોક, શુદ્ધ નફો.
**Customer:** મનની શાંતિ.
**Staff:** બિલમાં એક લાઇન — સહેલું upsell.
**Owner:** **લગભગ 100% માર્જિન**; સાથે ગ્રાહક બંધાય.
**Revenue Impact:** **ઊંચી** (દર બિલે ₹500-3,000 વધારાનું).
**Cost Saving:** —
**Automation:** બિલ સેવ થતાં expiry લંબાય, કાર્ડ PDF જાય.
**AI Opportunity:** કયા મોડેલમાં ખરાબી વધુ → ભાવ સાચો રાખવો (risk pricing).
**WhatsApp:** નવું વોરંટી કાર્ડ.
**Mobile/PWA:** બિલમાં ટિક.
**DB Tables:** `extended_warranties` (નવું), `item_serials.warranty_expiry` અપડેટ.
**APIs:** કોઈ નવું નહીં.
**Security:** expiry બદલવી = audit log માં **જરૂરી**.
**Complexity:** Medium
**Priority:** P1
**Monetization:** **સીધી આવક** — શ્રેષ્ઠ ROI ફીચર.
**Scalability:** સાદું.
**Example:** 60 લેપટોપ/વર્ષ × 30% લે × ₹2,500 = ₹45,000 શુદ્ધ નફો.

### #37 · Warranty Cost & Claim Ratio per Brand/Model 🔴
**Category:** Warranty
**શું છે?** કયા બ્રાન્ડ/મોડેલમાં **વોરંટી કેટલી મોંઘી પડે** છે.
**શું કામ કરે છે?** `warranty_claims` + `repair_materials` નો ખર્ચ ÷ વેચાણ = claim ratio, બ્રાન્ડ પ્રમાણે.
**Real-world Use Case:** એક બ્રાન્ડનું SMPS સસ્તું છે પણ 18% પાછું આવે છે.
**Customer:** પરોક્ષ (ભરોસાપાત્ર માલ).
**Staff:** કયું ન વેચવું.
**Owner:** **સસ્તા માલની છુપી કિંમત** દેખાય — ખરીદીનો નિર્ણય બદલાય.
**Revenue Impact:** પરોક્ષ ઊંચી.
**Cost Saving:** **ઊંચી**.
**Automation:** ત્રિમાસિક રિપોર્ટ.
**AI Opportunity:** નથી જરૂરી — ગુણોત્તર પૂરતો.
**WhatsApp:** ત્રિમાસિક સારાંશ.
**Mobile/PWA:** રિપોર્ટ.
**DB Tables:** કોઈ નવું નહીં.
**APIs:** `GET ?r=warranty_ratio`.
**Security:** `reports.profit`.
**Complexity:** Easy
**Priority:** P2
**Monetization:** "Analytics Pro".
**Scalability:** aggregate.
**Example:** "Brand X SMPS — 142 વેચ્યા, 26 claim (18%), ખર્ચ ₹19,400 → ખરીદી બંધ કરો."

### #38 · Warranty Void Conditions & Evidence Photos 🟡
**Category:** Warranty
**શું છે?** વોરંટી **કયા કારણે રદ** — સાબિતી સાથે (પાણી, બળેલું, sticker તૂટેલું).
**શું કામ કરે છે?** `repair_photos` છે; claim પર void કારણ + ફોટા જોડવા, ગ્રાહકને લખેલું જાય.
**Real-world Use Case:** પાણી પડેલું લેપટોપ વોરંટીમાં માગે — ના પાડવા પુરાવો જોઈએ.
**Customer:** કારણ સ્પષ્ટ; ફોટા સાથે, વિવાદ નહીં.
**Staff:** ના પાડવાની હિંમત મળે — સિસ્ટમનો આધાર.
**Owner:** **મફત રિપેર બંધ**; કંપની સામે પણ પુરાવો.
**Revenue Impact:** મધ્યમ (paid repair માં ફેરવાય).
**Cost Saving:** **ઊંચી**.
**Automation:** void થાય એટલે paid estimate આપોઆપ.
**AI Opportunity:** ફોટામાં પાણી/બળેલું ઓળખે — **સૂચન માત્ર**, નિર્ણય માણસનો.
**WhatsApp:** ફોટા + કારણ + paid ભાવ.
**Mobile/PWA:** ફોનથી ફોટા.
**DB Tables:** `warranty_claims.void_reason`, `warranty_void_reasons` (નવું).
**APIs:** `POST ?r=warranty_void`.
**Security:** void કરવું = `warranty.edit` + audit; ફોટા સચવાય.
**Complexity:** Easy
**Priority:** P1
**Monetization:** "Service Pro".
**Scalability:** ફોટા uploads માં.
**Example:** "Claim-88 વોરંટી બહાર: પાણી પડ્યું (3 ફોટા) → paid repair ₹3,200".

---

## CATEGORY 8 — REPAIR / SERVICE (7)

### #39 · Repair Estimate Approval by Customer (WhatsApp બટન) 🟡
**Category:** Repair/Service
**શું છે?** રિપેરનો ખર્ચ ગ્રાહક **મંજૂર કરે પછી** જ કામ શરૂ.
**શું કામ કરે છે?** job પર estimate → WhatsApp બટન → Approve/Reject → `repairs.status` બદલાય; મંજૂરી log માં.
**Real-world Use Case:** "SSD બદલવું પડશે, ₹3,400" — ફોન પર હા કહી હતી કે નહીં એ પછી વિવાદ.
**Customer:** ખર્ચ પર નિયંત્રણ; લખેલી મંજૂરી.
**Staff:** "હા કહ્યું હતું" નો પુરાવો.
**Owner:** **પૈસા ન આપવાના કજિયા બંધ**; કામ અટકે નહીં.
**Revenue Impact:** ઊંચી (approval ઝડપી = જોબ ઝડપી).
**Cost Saving:** ઊંચી (નકારાયેલા રિપેરનો પાર્ટ ખર્ચ બચે).
**Automation:** 24 કલાક જવાબ ન આવે તો રિમાઇન્ડર.
**AI Opportunity:** ખર્ચનું કારણ સાદી ભાષામાં લખે (ગ્રાહકને સમજાય).
**WhatsApp:** ✔ મુખ્ય માધ્યમ — બટન સાથે.
**Mobile/PWA:** technician ફોનથી estimate મોકલે.
**DB Tables:** `repairs.estimate_amount`, `repair_approvals` (નવું).
**APIs:** `POST ?r=repair_estimate`, `POST ?r=repair_decide` (token).
**Security:** token થી એક જ વાર; નિર્ણય audit.
**Complexity:** Medium
**Priority:** P0
**Monetization:** "Service Pro".
**Scalability:** સાદું.
**Example:** "₹3,400 — SSD 512GB + મજૂરી. મંજૂર? [હા] [ના]" → હા → કામ શરૂ.

### #40 · Technician Workload & Queue Board 🔴
**Category:** Repair/Service
**શું છે?** કયા technician પાસે **કેટલાં કામ** અને કયું પહેલાં.
**શું કામ કરે છે?** job નું અનુમાનિત સમય + priority → per-technician કતાર; drag કરીને ફેરવો.
**Real-world Use Case:** 3 technician, 22 pending job — કોને શું આપવું.
**Customer:** સાચી ડિલિવરી તારીખ મળે.
**Staff:** આજે શું કરવાનું એ સ્પષ્ટ.
**Owner:** **અટકેલાં કામ દેખાય**; ક્ષમતા ખબર પડે.
**Revenue Impact:** ઊંચી (વધુ જોબ/દિવસ).
**Cost Saving:** ઊંચી.
**Automation:** નવો job સૌથી ખાલી technician ને સૂચવે.
**AI Opportunity:** સમયનું અનુમાન (એ જ પ્રકારના જૂના job પરથી).
**WhatsApp:** technician ને સવારે એની યાદી.
**Mobile/PWA:** ✔ technician નું મુખ્ય સાધન.
**DB Tables:** `repairs.est_minutes`, `repairs.priority`, `repairs.queue_pos`.
**APIs:** `GET ?r=tech_queue`, `POST ?r=tech_assign`.
**Security:** technician ફક્ત પોતાનું; ફેરવવું = `repairs.edit`.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Service Pro".
**Scalability:** સાદું.
**Example:** "રાહુલ: 6 job (11 કલાક) · મહેશ: 3 job (4 કલાક) → નવો job મહેશને".

### #41 · Repair Diagnosis Checklist per Device Type 🟡
**Category:** Repair/Service
**શું છે?** લેપટોપ/પ્રિન્ટર/CCTV માટે **તૈયાર તપાસ-યાદી**.
**શું કામ કરે છે?** `repair_checklist` + `service_checklist_items` છે (અડધું વપરાયેલું); device પ્રકાર પ્રમાણે template.
**Real-world Use Case:** નવો technician પાવર ચેક કરવાનું ભૂલી જાય અને મધરબોર્ડ બદલી નાખે.
**Customer:** સાચું નિદાન, પહેલી વારમાં.
**Staff:** પગલાં ભૂલાય નહીં; તાલીમ built-in.
**Owner:** **ફરીથી આવતાં કામ (rework) ઘટે**; સાચું નિદાન = સાચી કમાણી.
**Revenue Impact:** મધ્યમ-ઊંચી.
**Cost Saving:** **ઊંચી** (ખોટો પાર્ટ બદલવાનું બંધ).
**Automation:** device પ્રકાર પસંદ કરો એટલે યાદી આવે.
**AI Opportunity:** #71 (AI Diagnosis) — લક્ષણ પરથી સંભવિત કારણ, **technician નક્કી કરે**.
**WhatsApp:** લાગુ નથી.
**Mobile/PWA:** ✔ ફોનમાં ટિક કરતાં જાવ.
**DB Tables:** પહેલેથી છે + `checklist_templates` (નવું).
**APIs:** `GET ?r=checklist&type=`.
**Security:** `repairs.edit`.
**Complexity:** Easy
**Priority:** P1
**Monetization:** "Service Pro".
**Scalability:** સાદું.
**Example:** લેપટોપ: પાવર → બેટરી → RAM reseat → ડિસ્પ્લે → SSD → OS (6 પગલાં).

### #42 · Parts Reserved for a Repair Job 🟡
**Category:** Repair/Service
**શું છે?** job માટે પાર્ટ **રોકી લેવો** — બીજું બિલ એ ન લઈ જાય.
**શું કામ કરે છે?** `stock_reservations` (અડધું બનેલું) job સાથે જોડવું.
**Real-world Use Case:** રિપેર માટે રાખેલું SSD કાઉન્ટર પર વેચાઈ જાય.
**Customer:** રિપેર સમયસર પૂરું.
**Staff:** પાર્ટ મળશે એની ખાતરી.
**Owner:** **રિપેર અટકવાનું બંધ**; ગ્રાહકને આપેલી તારીખ સચવાય.
**Revenue Impact:** મધ્યમ.
**Cost Saving:** મધ્યમ.
**Automation:** job બંધ થાય એટલે reservation છૂટે.
**AI Opportunity:** નથી.
**WhatsApp:** લાગુ નથી.
**Mobile/PWA:** હા.
**DB Tables:** `stock_reservations` (પહેલેથી છે).
**APIs:** `POST ?r=reserve` (#4 નું જ).
**Security:** `stock.adjust`.
**Complexity:** Easy
**Priority:** P1
**Monetization:** core.
**Scalability:** સાદું.
**Example:** Job-441 માટે 1 SSD reserved → કાઉન્ટર પર available 3 ને બદલે 2 દેખાય.

### #43 · Repair Warranty (કામની વોરંટી) 🟡
**Category:** Repair/Service
**શું છે?** **રિપેર** પર વોરંટી — 30/90 દિવસ, એ જ ખરાબી ફરી આવે તો મફત.
**શું કામ કરે છે?** job પર `warranty_days`; એ જ device+ખરાબી ફરી આવે તો "વોરંટીમાં" તરીકે ખૂલે.
**Real-world Use Case:** 20 દિવસમાં એ જ સમસ્યા — પૈસા ફરી લેવા કે નહીં એ ઝઘડો.
**Customer:** ભરોસો; લખેલી શરત.
**Staff:** નિયમ સ્પષ્ટ, દલીલ નહીં.
**Owner:** **ભરોસો = repeat**; સાથે rework નો ખર્ચ માપી શકાય.
**Revenue Impact:** ઊંચી (ભરોસાથી વધુ રિપેર આવે).
**Cost Saving:** rework માપીને ઘટાડાય.
**Automation:** job બંધ થાય એટલે વોરંટી તારીખ + WhatsApp.
**AI Opportunity:** કયા technician નું rework વધુ — તાલીમનો સંકેત.
**WhatsApp:** "આ રિપેર પર 90 દિવસની વોરંટી — 2026-12-27 સુધી."
**Mobile/PWA:** હા.
**DB Tables:** `repairs.warranty_days`, `repairs.rework_of` (નવાં).
**APIs:** કોઈ નવું નહીં.
**Security:** `repairs.edit`.
**Complexity:** Easy
**Priority:** P1
**Monetization:** "Service Pro".
**Scalability:** સાદું.
**Example:** "Job-441 — 90 દિવસ વોરંટી; Job-502 એનું rework (મફત)".

### #44 · Pickup & Drop Logistics 🔴
**Category:** Repair/Service
**શું છે?** ઘરેથી **લેવા-આપવા** જવાનું — કોણ, ક્યારે, કયા રસ્તે.
**શું કામ કરે છે?** job પર pickup/drop slot; દિવસની યાદી રસ્તા પ્રમાણે; OTP થી સોંપણી.
**Real-world Use Case:** દ્વારકામાં ઘરે જઈને લેપટોપ લેવું — રોજ 4-5 જગ્યા.
**Customer:** દુકાને આવવું ન પડે — **મોટી સગવડ, વધારાની આવક**.
**Staff:** રસ્તો ગોઠવેલો; OTP થી પુરાવો.
**Owner:** **premium સર્વિસ ચાર્જ** લઈ શકાય.
**Revenue Impact:** **ઊંચી** (નવી આવક + વધુ ગ્રાહક).
**Cost Saving:** પેટ્રોલ/સમય (રસ્તો ગોઠવીને).
**Automation:** slot booking + રિમાઇન્ડર.
**AI Opportunity:** રસ્તાનો ક્રમ (સાદું nearest-first પહેલાં).
**WhatsApp:** "કાલે 11-1 વચ્ચે લેવા આવીશું — બરાબર?"
**Mobile/PWA:** ✔ છોકરાના ફોનમાં આજની યાદી.
**DB Tables:** `pickups` (નવું).
**APIs:** `POST ?r=pickup`, `GET ?r=pickup_today`.
**Security:** સોંપણી OTP (હાલની `otp_codes` પદ્ધતિ).
**Complexity:** Medium
**Priority:** P2
**Monetization:** સીધો ચાર્જ.
**Scalability:** સાદું.
**Example:** "આજે 5 pickup — રસ્તો: સ્ટેશન → બજાર → ગોમતી ઘાટ".

### #45 · Job Profitability (પાર્ટ + મજૂરી + સમય) 🟡
**Category:** Repair/Service
**શું છે?** દરેક રિપેરમાં **ખરેખર કેટલો નફો**.
**શું કામ કરે છે?** `repair_materials` નો cost + technician નો સમય × દર vs વસૂલેલી રકમ.
**Real-world Use Case:** ₹800 નું રિપેર જેમાં 3 કલાક ગયા — નુકસાન.
**Customer:** પરોક્ષ (ભાવ સાચા).
**Staff:** પોતાની ઉત્પાદકતા દેખાય.
**Owner:** **કયું રિપેર કરવું જ નહીં** એ ખબર પડે; મજૂરીનો દર સુધારાય.
**Revenue Impact:** ઊંચી (ભાવ સુધારીને).
**Cost Saving:** ઊંચી.
**Automation:** job બંધ થાય એટલે ગણાય.
**AI Opportunity:** કયા પ્રકારનાં કામ નફાકારક — ભાવયાદી સૂચવે.
**WhatsApp:** માસિક સારાંશ.
**Mobile/PWA:** રિપોર્ટ.
**DB Tables:** `repairs.labour_minutes`, `settings.labour_rate`.
**APIs:** `GET ?r=job_profit`.
**Security:** `reports.profit`.
**Complexity:** Easy
**Priority:** P1
**Monetization:** "Analytics Pro".
**Scalability:** સાદું.
**Example:** "પ્રિન્ટર રિપેર સરેરાશ ₹620 નફો/કલાક · લેપટોપ મધરબોર્ડ ₹180/કલાક → ભાવ વધારો".

---
## CATEGORY 9 — CCTV / NETWORKING (6)

### #46 · CCTV Site Survey → Quotation Calculator 🔴
**Category:** CCTV/Networking
**શું છે?** સાઇટ જોઈને **કેમેરા ગણો** → આખું ક્વોટેશન આપોઆપ (કેમેરા, DVR/NVR, HDD, PoE, વાયર, મજૂરી).
**શું કામ કરે છે?** કેમેરાની સંખ્યા + પ્રકાર નાખો → channel count → DVR પસંદ → HDD (#47) → PoE (#48) → વાયર (#49) → મજૂરી → estimate.
**Real-world Use Case:** દુકાનમાં 8 કેમેરા — અત્યારે આ ગણતરી કાગળ પર અને ભૂલો થાય.
**Customer:** સાઇટ પર જ ભાવ મળે — સોદો ત્યાં જ પાકે.
**Staff:** ગણતરીની ભૂલ બંધ; સેલ્સમેન પણ કરી શકે.
**Owner:** **સૌથી નફાકારક કામ ઝડપી બંધ થાય**; કંઈ ભૂલાય નહીં (ભૂલાયેલો પાર્ટ = ખાધેલો નફો).
**Revenue Impact:** **સૌથી ઊંચી** — CCTV project = મોટી ટિકિટ.
**Cost Saving:** ઊંચી (ભૂલાયેલા પાર્ટ, ફરી જવું).
**Automation:** આખી ગણતરી.
**AI Opportunity:** સાઇટના ફોટા પરથી કેમેરાની જગ્યા સૂચવે — **સૂચન માત્ર**.
**WhatsApp:** સાઇટ પરથી જ ક્વોટ PDF.
**Mobile/PWA:** ✔ સાઇટ પર ફોનમાં ભરાય.
**DB Tables:** `site_surveys`, `survey_items` (નવાં); `sites` સાથે જોડાણ.
**APIs:** `POST ?r=survey`, `POST ?r=survey_quote`.
**Security:** `sites.add`; ભાવ margin floor સાથે.
**Complexity:** Medium
**Priority:** P0
**Monetization:** **CCTV પેકેજ — સ્વતંત્ર રીતે વેચી શકાય**.
**Scalability:** સાદું.
**Example:** 8 × 2MP dome + 1 × 8ch DVR + 2TB + 90m વાયર + મજૂરી = ₹38,400.

### #47 · HDD Storage / Recording Days Calculator 🔴
**Category:** CCTV/Networking
**શું છે?** આટલા કેમેરાનું આટલા દિવસનું રેકોર્ડિંગ રાખવા **કેટલી TB** જોઈએ.
**શું કામ કરે છે?** bitrate × કેમેરા × કલાક × દિવસ ÷ 8 = GB; resolution/FPS/compression (H.264/H.265) પ્રમાણે.
**Real-world Use Case:** ગ્રાહક "30 દિવસનું રેકોર્ડિંગ જોઈએ" કહે — HDD નું માપ ખોટું પડે તો ફરી જવું પડે.
**Customer:** સાચી અપેક્ષા; પછી ફરિયાદ નહીં.
**Staff:** ગણતરી કરવી ન પડે.
**Owner:** **ફરી જવાનું બંધ**; મોટી HDD નું upsell પણ સાચા કારણે.
**Revenue Impact:** મધ્યમ-ઊંચી (HDD upsell).
**Cost Saving:** ઊંચી (ફરી વિઝિટ).
**Automation:** survey માં આપોઆપ.
**AI Opportunity:** નથી — શુદ્ધ ગણિત.
**WhatsApp:** ક્વોટમાં "30 દિવસ = 4TB".
**Mobile/PWA:** હા.
**DB Tables:** `settings` માં bitrate ધારણા.
**APIs:** `POST ?r=hdd_calc`.
**Security:** કોઈ નહીં.
**Complexity:** Easy
**Priority:** P1
**Monetization:** CCTV પેકેજ.
**Scalability:** ગણતરી માત્ર.
**Example:** 8 કેમેરા × 2MP × H.265 × 24h × 30 દિ = ~3.8TB → **4TB ભલામણ**.

### #48 · PoE Switch & Power Budget Calculator 🔴
**Category:** CCTV/Networking
**શું છે?** કેટલા port અને **કેટલા watt** નો PoE switch.
**શું કામ કરે છે?** કેમેરા પ્રતિ watt (PoE/PoE+) જોડે + 20% headroom; port = કેમેરા + spare.
**Real-world Use Case:** 8 IP કેમેરા પર 8-port 65W switch નાખો → ચાલે નહીં.
**Customer:** સિસ્ટમ સ્થિર.
**Staff:** ભૂલ બંધ.
**Owner:** **પાછળથી switch બદલવાનો ખર્ચ બચે**.
**Revenue Impact:** મધ્યમ.
**Cost Saving:** ઊંચી.
**Automation:** survey માં.
**AI Opportunity:** નથી.
**WhatsApp:** ક્વોટમાં.
**Mobile/PWA:** હા.
**DB Tables:** `item_specs.poe_watt`.
**APIs:** `POST ?r=poe_calc`.
**Security:** કોઈ નહીં.
**Complexity:** Easy
**Priority:** P1
**Monetization:** CCTV પેકેજ.
**Scalability:** ગણતરી.
**Example:** 8 × 7W = 56W + 20% = 68W → **8-port 120W** (65W નહીં).

### #49 · Cable & Consumable Estimation 🔴
**Category:** CCTV/Networking
**શું છે?** વાયર, connector, conduit, clip — **કેટલું** જોઈશે.
**શું કામ કરે છે?** કેમેરા પ્રતિ સરેરાશ લંબાઈ (સેટિંગ) + 15% waste; consumable kit આપોઆપ.
**Real-world Use Case:** 90m વાયર લઈ ગયા, 110 જોઈતું — કામ અટક્યું.
**Customer:** કામ એક જ વારમાં પૂરું.
**Staff:** ગાડીમાં શું ભરવું એ યાદી.
**Owner:** **અડધું કામ છોડીને પાછા આવવાનું બંધ**.
**Revenue Impact:** મધ્યમ.
**Cost Saving:** **ઊંચી** (વધારાની વિઝિટ ₹500-1,500).
**Automation:** survey માંથી picking list.
**AI Opportunity:** જૂના પ્રોજેક્ટના વપરાશ પરથી સરેરાશ સુધારે.
**WhatsApp:** technician ને લોડિંગ યાદી.
**Mobile/PWA:** હા.
**DB Tables:** `survey_items` (#46).
**APIs:** `GET ?r=survey_picklist`.
**Security:** `stock.view`.
**Complexity:** Easy
**Priority:** P1
**Monetization:** CCTV પેકેજ.
**Scalability:** સાદું.
**Example:** 8 કેમેરા × 14m + 15% = 129m → 130m + 16 BNC + 2 જાળી.

### #50 · Installation Job Card with Site Photos & Sign-off 🟡
**Category:** CCTV/Networking
**શું છે?** ઇન્સ્ટોલેશનનું **પુરાવા સાથેનું** કામ-કાર્ડ — પહેલાં/પછીના ફોટા, ગ્રાહકની સહી.
**શું કામ કરે છે?** `tasks` + `repair_photos` નું pattern; સહી (પહેલેથી છે) + પૂરું થયાની પુષ્ટિ.
**Real-world Use Case:** "કેમેરો ત્યાં નહોતો લગાવવાનો" — 2 મહિના પછીનો ઝઘડો.
**Customer:** શું થયું એ લખેલું + ફોટા.
**Staff:** કામ પૂરું થયાનો પુરાવો.
**Owner:** **ઝઘડા બંધ**; મજૂરીના પૈસા અટકે નહીં.
**Revenue Impact:** મધ્યમ (ઉઘરાણી ઝડપી).
**Cost Saving:** ઊંચી.
**Automation:** sign-off થાય એટલે બિલ તૈયાર.
**AI Opportunity:** ફોટામાં કેમેરા ગણે (ગણતરી મેળવવા) — સૂચન માત્ર.
**WhatsApp:** ફોટા + સહી ગ્રાહકને.
**Mobile/PWA:** ✔ સાઇટ પર ફોન.
**DB Tables:** `tasks` + `task_photos` (નવું).
**APIs:** `POST ?r=task_photo`, `POST ?r=task_signoff`.
**Security:** સહી immutable; ફોટા audit સાથે.
**Complexity:** Medium
**Priority:** P1
**Monetization:** CCTV પેકેજ.
**Scalability:** ફોટા uploads માં.
**Example:** 8 ફોટા + સહી → બિલ ₹38,400 તરત.

### #51 · AMC Visit Schedule & Proof 🟡
**Category:** CCTV/Networking
**શું છે?** AMC માં **ક્યારે ક્યારે જવાનું** અને ગયા એનો પુરાવો.
**શું કામ કરે છે?** `amc_contracts` છે; visit schedule + checklist + ફોટા + સહી ઉમેરવું.
**Real-world Use Case:** વર્ષના 4 વિઝિટ વાળું AMC — 2 જ થયાં, ગ્રાહકને ખબર પડે તો renewal જાય.
**Customer:** સર્વિસ ખરેખર મળે.
**Staff:** આ મહિને કઈ સાઇટ — યાદી.
**Owner:** **AMC renewal બચે**; વિઝિટ ન થવાથી થતું નુકસાન બંધ.
**Revenue Impact:** **ઊંચી** (renewal = પુનરાવર્તિત આવક).
**Cost Saving:** મધ્યમ.
**Automation:** schedule આપોઆપ; રિમાઇન્ડર.
**AI Opportunity:** કયું AMC renew નહીં થાય એનું જોખમ.
**WhatsApp:** "કાલે AMC વિઝિટ — 11 વાગ્યે આવીશું."
**Mobile/PWA:** ✔ technician.
**DB Tables:** `amc_visits` (નવું).
**APIs:** `GET ?r=amc_due`, `POST ?r=amc_visit`.
**Security:** `amc.edit`.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Service Pro".
**Scalability:** સાદું.
**Example:** "Hotel Sagar — 4/4 વિઝિટ પૂરાં ✔ renewal 12 તા."

---

## CATEGORY 10 — E-COMMERCE (6)

### #52 · Product Comparison (સામસામે) 🔴
**Category:** E-commerce
**શું છે?** 2-4 પ્રોડક્ટ **સામસામે** — spec, ભાવ, સ્ટોક.
**શું કામ કરે છે?** `item_specs` પરથી કૉલમ; "Compare" ટિક કરીને.
**Real-world Use Case:** બે લેપટોપ વચ્ચે ગ્રાહક નક્કી ન કરી શકે → જતો રહે.
**Customer:** જાતે નક્કી કરી શકે; વિશ્વાસ.
**Staff:** સરખામણી સમજાવવાનો સમય બચે.
**Owner:** **નિર્ણય ઝડપી = વેચાણ**; ઊંચા મોડેલ તરફ દોરી શકાય.
**Revenue Impact:** ઊંચી.
**Cost Saving:** સ્ટાફનો સમય.
**Automation:** spec માંથી આપોઆપ.
**AI Opportunity:** "તમારા વપરાશ માટે આ સારું" — કારણ સાથે.
**WhatsApp:** સરખામણી image/PDF.
**Mobile/PWA:** ✔ મોબાઇલ-first.
**DB Tables:** `item_specs`.
**APIs:** `GET ?r=compare&ids=`.
**Security:** જાહેર; ભાવ dealer_price પ્રમાણે.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Store Pro".
**Scalability:** cache.
**Example:** "Dell 3520 vs HP 250 — RAM 8/8, SSD 512/256, ભાવ ₹42,000/₹38,000".

### #53 · Frequently Bought Together / Cross-sell 🟡
**Category:** E-commerce
**શું છે?** "આની સાથે આ પણ" — અસલી બિલ ડેટા પરથી.
**શું કામ કરે છે?** `purchase_intel` માં "આની સાથે આ વેચાય છે" છે; એને કેટલોગ/બિલ સ્ક્રીન પર લાવવું.
**Real-world Use Case:** પ્રિન્ટર સાથે કારતૂસ, કેમેરા સાથે HDD.
**Customer:** ભૂલાય નહીં.
**Staff:** upsell યાદ કરાવે.
**Owner:** **સરેરાશ બિલ સીધું વધે** — સૌથી સસ્તો revenue boost.
**Revenue Impact:** **ઊંચી** (5-15% basket).
**Cost Saving:** —
**Automation:** સંપૂર્ણ.
**AI Opportunity:** market-basket; પણ **ગણતરી પહેલાં, AI પછી** (પહેલેથી ગણતરી છે).
**WhatsApp:** બોટમાં "સાથે લેશો?"
**Mobile/PWA:** બિલ સ્ક્રીન પર સૂચન.
**DB Tables:** કોઈ નવું નહીં.
**APIs:** `GET ?r=together&item_id=`.
**Security:** કોઈ નહીં.
**Complexity:** Easy
**Priority:** P0
**Monetization:** "Store Pro".
**Scalability:** રાત્રે ગણાય.
**Example:** પ્રિન્ટર → 78% બિલમાં કારતૂસ પણ → સૂચન.

### #54 · Coupons & Offer Rules 🔴
**Category:** E-commerce
**શું છે?** કૂપન કોડ અને નિયમ-આધારિત ઓફર (₹500 off ₹5,000 ઉપર).
**શું કામ કરે છે?** `coupons` + `coupon_uses`; margin floor સાથે ટકરાય તો રોકે.
**Real-world Use Case:** દિવાળી ઓફર — હાથે ડિસ્કાઉન્ટ આપતાં માર્જિન ખતમ થાય.
**Customer:** સાચી બચત, સ્પષ્ટ શરત.
**Staff:** કોડ નાખો, બસ — દલીલ નહીં.
**Owner:** **ડિસ્કાઉન્ટ પર નિયંત્રણ**; કઈ ઓફર ચાલી એ માપી શકાય.
**Revenue Impact:** ઊંચી (માપી શકાય તેવી ઓફર).
**Cost Saving:** ઊંચી (બેકાબૂ ડિસ્કાઉન્ટ બંધ).
**Automation:** expiry, વપરાશ મર્યાદા.
**AI Opportunity:** કેટલું ડિસ્કાઉન્ટ પૂરતું છે — ઇતિહાસ પરથી.
**WhatsApp:** કૂપન કોડ campaign માં.
**Mobile/PWA:** checkout માં.
**DB Tables:** `coupons`, `coupon_uses` (નવાં).
**APIs:** `POST ?r=coupon_check`.
**Security:** એક કૂપન એક વાર; margin floor bypass **નહીં**; audit.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Marketing Pro".
**Scalability:** સાદું.
**Example:** DIWALI500 — ₹5,000+ પર ₹500, 100 વપરાશ, 15 તા. સુધી.

### #55 · Store Pickup vs Delivery slot 🟡
**Category:** E-commerce
**શું છે?** ઓર્ડરમાં **દુકાનેથી લેવું કે ઘરે મંગાવવું** — સમય સાથે.
**શું કામ કરે છે?** `web_orders` માં fulfilment પ્રકાર + slot; pickup માટે reservation (#4).
**Real-world Use Case:** દ્વારકામાં ઘણા ગ્રાહક જાતે લેવા આવે — પણ માલ તૈયાર જોઈએ.
**Customer:** ડિલિવરીની રાહ નહીં; સમય નક્કી.
**Staff:** આજે કેટલા pickup — તૈયારી.
**Owner:** ડિલિવરી ખર્ચ બચે; ગ્રાહક દુકાને આવે = વધુ વેચાણ.
**Revenue Impact:** મધ્યમ-ઊંચી (દુકાને આવેલો ગ્રાહક વધુ લે).
**Cost Saving:** ઊંચી (ડિલિવરી).
**Automation:** "તૈયાર છે" મેસેજ.
**AI Opportunity:** નથી.
**WhatsApp:** "તમારો ઓર્ડર તૈયાર — 6 વાગ્યા સુધી લઈ જાવ."
**Mobile/PWA:** checkout માં પસંદગી.
**DB Tables:** `web_orders.fulfilment`, `web_orders.slot`.
**APIs:** `POST ?r=worder` માં ઉમેરો.
**Security:** pickup OTP થી સોંપણી.
**Complexity:** Easy
**Priority:** P1
**Monetization:** core.
**Scalability:** સાદું.
**Example:** "આજે 4 pickup (11-1: 2, 5-7: 2) · 1 ડિલિવરી".

### #56 · Stock-aware Online Catalog (over-selling રોકે) 🟡
**Category:** E-commerce
**શું છે?** ઓનલાઇન ઓર્ડર **ઉપલબ્ધ સ્ટોક કરતાં વધુ** ન લે.
**શું કામ કરે છે?** `?r=worder` માં stock check + reservation; 0 હોય તો "ઓર્ડર પર" સ્પષ્ટ.
**Real-world Use Case:** વેબસાઈટ પરથી 5 નો ઓર્ડર આવે, 2 જ છે — ગ્રાહકને ના પાડવી પડે.
**Customer:** **ખોટું વચન નહીં** — વિશ્વાસ.
**Staff:** શરમજનક ફોન બંધ.
**Owner:** પ્રતિષ્ઠા બચે; ઓર્ડર રદ ઘટે.
**Revenue Impact:** મધ્યમ (રદ ઘટે).
**Cost Saving:** મધ્યમ.
**Automation:** reservation + release.
**AI Opportunity:** નથી.
**WhatsApp:** "2 જ ઉપલબ્ધ — બાકી 3 ઓર્ડર પર (5 દિવસ)?"
**Mobile/PWA:** કેટલોગમાં સાચો સ્ટોક (પહેલેથી છે).
**DB Tables:** `stock_reservations`.
**APIs:** `?r=worder` સુધારો.
**Security:** stock check **સર્વર પર** જ.
**Complexity:** Medium
**Priority:** P0
**Monetization:** core.
**Scalability:** ઓર્ડર પ્રતિ એક ચેક.
**Example:** ઓર્ડર 5 → 2 confirm + 3 backorder, ગ્રાહકને સ્પષ્ટ.

### #57 · Customer Order Tracking page 🟡
**Category:** E-commerce
**શું છે?** ઓર્ડર **ક્યાં પહોંચ્યો** — ગ્રાહકનું પોતાનું પાનું.
**શું કામ કરે છે?** token થી ખૂલતું પાનું: મળ્યો → તૈયાર → નીકળ્યો → પહોંચ્યો; દરેક પગલે WhatsApp.
**Real-world Use Case:** "મારો ઓર્ડર ક્યાં છે?" — રોજના ફોન.
**Customer:** જાતે જોઈ શકે.
**Staff:** ફોન ઘટે.
**Owner:** professional; સપોર્ટ ખર્ચ ઘટે.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** ઊંચી.
**Automation:** સ્ટેટસ બદલાય એટલે મેસેજ.
**AI Opportunity:** નથી.
**WhatsApp:** ✔ દરેક પગલે.
**Mobile/PWA:** ગ્રાહકનું પાનું.
**DB Tables:** `web_orders.status` (છે) + `web_order_events` (નવું).
**APIs:** `GET ?r=order_track&token=`.
**Security:** token; ફક્ત એ ઓર્ડરની વિગત.
**Complexity:** Easy
**Priority:** P1
**Monetization:** core.
**Scalability:** સાદું.
**Example:** "WEB-00042 — તૈયાર છે, આજે સાંજે નીકળશે".

---

## CATEGORY 11 — WHATSAPP (4)

### #58 · Broadcast Template Approval Pipeline 🟡
**Category:** WhatsApp
**શું છે?** Meta ના template **મંજૂર થયા કે નહીં** એ સિસ્ટમમાં જ સંભાળવું.
**શું કામ કરે છે?** `wa_meta.php` માં sync છે; rejected template નું કારણ + ફરી સબમિટ કરવાનું પાનું.
**Real-world Use Case:** template reject થાય તો રિમાઇન્ડર ચૂપચાપ બંધ થઈ જાય.
**Customer:** મેસેજ ખરેખર પહોંચે.
**Staff:** કંઈ કરવાનું નહીં.
**Owner:** **ઉઘરાણીના મેસેજ બંધ થવાનું જોખમ** ખતમ.
**Revenue Impact:** ઊંચી (ઉઘરાણી ચાલુ રહે).
**Cost Saving:** મધ્યમ.
**Automation:** 6 કલાકે status refresh (પહેલેથી છે) + alert.
**AI Opportunity:** reject થયેલું લખાણ policy મુજબ ફરી લખે — માણસ મંજૂર કરે.
**WhatsApp:** માલિકને "template reject થયું".
**Mobile/PWA:** જોવા પૂરતું.
**DB Tables:** `wa_templates` (નવું).
**APIs:** `POST ?r=wa_template_submit`.
**Security:** admin જ; token સુરક્ષિત.
**Complexity:** Medium
**Priority:** P1
**Monetization:** core.
**Scalability:** સાદું.
**Example:** "3 template Approved, 1 Rejected (policy) — ફરી લખેલું તૈયાર, મંજૂર કરો?"

### #59 · Delivery Receipts (ગયો? વંચાયો?) 🟡
**Category:** WhatsApp
**શું છે?** મેસેજ **પહોંચ્યો અને વંચાયો** કે નહીં.
**શું કામ કરે છે?** Meta webhook ના status callbacks `wa_chats` માં નોંધવા.
**Real-world Use Case:** ઉઘરાણીનો મેસેજ ગયો કે નહીં એ ખબર ન પડે → ફરી ફોન.
**Customer:** વારંવાર મેસેજ ન આવે.
**Staff:** કોને ફોન કરવો (જેને મેસેજ ન પહોંચ્યો).
**Owner:** **ઉઘરાણીની મહેનત સાચી જગ્યાએ**.
**Revenue Impact:** મધ્યમ-ઊંચી.
**Cost Saving:** મધ્યમ.
**Automation:** ન પહોંચે તો ફોન-યાદીમાં.
**AI Opportunity:** નથી.
**WhatsApp:** ✔ આ એનું જ ફીચર.
**Mobile/PWA:** સ્ટેટસ દેખાય.
**DB Tables:** `wa_chats.delivery_status` (કૉલમ).
**APIs:** હાલની `wa_webhook.php`.
**Security:** webhook signature verify **જરૂરી**.
**Complexity:** Easy
**Priority:** P1
**Monetization:** core.
**Scalability:** webhook volume — queue.
**Example:** "34 મોકલ્યા · 31 પહોંચ્યા · 19 વંચાયા · 3 નંબર બંધ → એ 3 ને ફોન".

### #60 · Two-way Repair Chat on the Job 🟡
**Category:** WhatsApp
**શું છે?** ગ્રાહકનો જવાબ **સીધો job સાથે** જોડાય.
**શું કામ કરે છે?** `wa_chats` છે; incoming મેસેજ pending job સાથે match કરીને job ના timeline માં.
**Real-world Use Case:** "કરી નાખો" નો જવાબ WhatsApp માં આવે અને technician ને ખબર ન પડે.
**Customer:** WhatsApp માં જ વાત.
**Staff:** આખી વાત job પર જ.
**Owner:** **મંજૂરી ખોવાય નહીં**; વિવાદમાં પુરાવો.
**Revenue Impact:** મધ્યમ.
**Cost Saving:** ઊંચી.
**Automation:** નંબર પરથી job match.
**AI Opportunity:** જવાબનો ભાવ (હા/ના/પ્રશ્ન) ઓળખે → status સૂચવે.
**WhatsApp:** ✔ મુખ્ય.
**Mobile/PWA:** technician ને notification.
**DB Tables:** `wa_chats.repair_id` (કૉલમ).
**APIs:** હાલની webhook.
**Security:** ફક્ત એ જ નંબરના મેસેજ જોડાય.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Service Pro".
**Scalability:** index પર match.
**Example:** "હા કરી નાખો" → Job-441 timeline + technician ને alert.

### #61 · WhatsApp Payment Link + auto-settle 🟡
**Category:** WhatsApp
**શું છે?** ઉઘરાણી સાથે **ચૂકવવાની link**; પૈસા આવે એટલે બિલ આપોઆપ ચૂકતે.
**શું કામ કરે છે?** `pay.php` + `razorpay_webhook.php` છે; રિમાઇન્ડરમાં link + webhook થી payment એન્ટ્રી.
**Real-world Use Case:** ગ્રાહક "કાલે આવીને આપીશ" — link હોય તો ત્યારે જ આપી દે.
**Customer:** તરત ચૂકવી શકે.
**Staff:** entry કરવી ન પડે.
**Owner:** **ઉઘરાણી દિવસો ઘટે** — સીધો રોકડ પ્રવાહ.
**Revenue Impact:** **ઊંચી** (DSO ઘટે).
**Cost Saving:** ઊંચી (ઉઘરાણીની મહેનત).
**Automation:** webhook → payment → allocation → બિલ ચૂકતે.
**AI Opportunity:** નથી — પૈસા deterministic.
**WhatsApp:** ✔ link રિમાઇન્ડરમાં.
**Mobile/PWA:** ગ્રાહકનું UPI.
**DB Tables:** કોઈ નવું નહીં.
**APIs:** હાલની webhook.
**Security:** webhook signature; idempotency (એક જ payment બે વાર નહીં).
**Complexity:** Medium
**Priority:** P0
**Monetization:** core — **સૌથી સીધો રોકડ લાભ**.
**Scalability:** webhook.
**Example:** ₹12,400 નું રિમાઇન્ડર + link → 20 મિનિટમાં ચૂકવાયું → બિલ ચૂકતે.

---
## CATEGORY 12 — AI / AUTOMATION INTELLIGENCE (13)

> **નિયમ (બદલાય નહીં):** AI ને database માં **લખવાની સીધી પરવાનગી કદી નહીં**.
> પ્રવાહ હંમેશા: **AI સૂચન → નિયમથી ચકાસણી → માણસની મંજૂરી → transaction**.
> પૈસાની ગણતરી હંમેશા **નિશ્ચિત કોડ** (deterministic) — AI નહીં.
> ભાવ / માર્જિન / ખાતાવહી પર AI ને **સત્તા નહીં**.
> ગ્રાહકની ખાનગી વિગત બહારના AI ને **જરૂર વગર નહીં**.
> દરેક call નો **ખર્ચ નોંધાય** અને મહિનાની **budget cap** પછી બંધ.
> AI બગડે તો **સાદું fallback** ચાલુ રહે — કામ અટકે નહીં.

### #62 · AI Gateway: ખર્ચ, બજેટ અને fallback 🔴
**Category:** AI
**શું છે?** બધા AI call એક **દરવાજા** માંથી — token, ખર્ચ, બજેટ, fallback એક જગ્યાએ.
**શું કામ કરે છે?** `ai_call($purpose, $prompt, $opts)` — મહિનાની cap ચેક, ખર્ચ નોંધ, cap પૂરી થાય કે provider બગડે તો **નિયમ-આધારિત જવાબ**.
**Real-world Use Case:** AI બિલ ₹8,000 આવે અથવા provider બંધ પડે — બંને સ્થિતિમાં દુકાન ચાલવી જોઈએ.
**Customer:** કંઈ બંધ ન થાય.
**Staff:** AI ન હોય તો પણ સ્ક્રીન કામ કરે.
**Owner:** **AI નો ખર્ચ નિયંત્રણમાં**; મહિનાનું આંકડું સ્પષ્ટ.
**Revenue Impact:** પરોક્ષ (બીજાં બધાં AI ફીચર આના પર ઊભાં).
**Cost Saving:** **સૌથી ઊંચી** — બેકાબૂ AI બિલ અટકે.
**Automation:** cap, retry, cache.
**AI Opportunity:** આ **જ** AI નું પાયાનું ફીચર.
**WhatsApp:** cap 80% થાય તો માલિકને.
**Mobile/PWA:** ખર્ચનું કાર્ડ.
**DB Tables:** `ai_calls`, `ai_budget` (નવાં).
**APIs:** અંદરનું; `GET ?r=ai_cost`.
**Security:** API key **encrypted** (`app_secret_*` pattern); prompt માં ગ્રાહકનું નામ/નંબર **નહીં** (id વાપરો).
**Complexity:** Medium
**Priority:** **P0 — બીજું કોઈ AI ફીચર આ પહેલાં નહીં**
**Monetization:** AI પેકેજનો પાયો.
**Scalability:** cache + queue.
**Example:** "આ મહિને ₹412 / ₹1,000 · 183 call · 2 fallback".

### #63 · AI PC Builder (સૂચન) 🔴 · PC Builder મોડ્યુલ 4/5
**Category:** AI
**શું છે?** "₹45,000 માં ઓફિસનું PC" કહો → **પૂરી યાદી** સૂચવે.
**શું કામ કરે છે?** બજેટ + વપરાશ → AI પ્રાથમિક યાદી → **#30 compatibility + #31 PSU + સ્ટોક + margin floor થી ચકાસણી** → જે ન ચાલે એ બદલાય → માણસ મંજૂર કરે → #29 ક્વોટ.
**Real-world Use Case:** ગ્રાહક બજેટ કહે; યાદી બનાવવામાં 20 મિનિટ જાય.
**Customer:** તરત વિકલ્પ; 3 બજેટ (સાદું/મધ્યમ/સારું).
**Staff:** નવો સ્ટાફ પણ PC બનાવી શકે.
**Owner:** **જે માલ પડ્યો છે એને પહેલાં** સૂચવે (#14, dead stock) = રોકડ છૂટે.
**Revenue Impact:** **ઊંચી**.
**Cost Saving:** ઊંચી (સમય).
**Automation:** સૂચન; મંજૂરી માણસની.
**AI Opportunity:** ✔ મુખ્ય — **પણ સૂચન માત્ર**.
**WhatsApp:** 3 વિકલ્પ PDF.
**Mobile/PWA:** હા.
**DB Tables:** `pc_builds`, `pc_build_items` (#29 સાથે).
**APIs:** `POST ?r=ai_build` (સૂચન પરત, કંઈ save નહીં).
**Security:** ભાવ **સર્વર પર**; AI ભાવ બદલી ન શકે; ચકાસણી નિષ્ફળ = સૂચન રદ.
**Complexity:** Hard
**Priority:** P1
**Monetization:** "AI Pro".
**Scalability:** પરિણામ cache.
**Example:** ₹45,000 → i3-12100 + H610 + 8GB + 512 SSD + 450W = ₹43,800 (સ્ટોકમાં 5/6, 1 ઓર્ડર પર).

### #64 · AI Repair Diagnosis Assistant 🔴
**Category:** AI
**શું છે?** લક્ષણ લખો → **સંભવિત કારણ, જોવાનાં પગલાં, પાર્ટ, અંદાજિત સમય**.
**શું કામ કરે છે?** જૂના `repairs` ના `problem→solution` પરથી (RAG) + AI; #41 checklist બને.
**Real-world Use Case:** "લેપટોપ ચાલુ થાય, સ્ક્રીન કાળી" — નવો technician અટકે.
**Customer:** ઝડપી નિદાન.
**Staff:** **શીખવાનું સાધન**; સિનિયરને પૂછવું ઘટે.
**Owner:** સિનિયર પર આધાર ઘટે; કામ ઝડપી = વધુ job.
**Revenue Impact:** ઊંચી (દિવસના વધુ job).
**Cost Saving:** ઊંચી.
**Automation:** સૂચન.
**AI Opportunity:** ✔ RAG — **દુકાનનો પોતાનો ઇતિહાસ** સૌથી કીમતી ડેટા.
**WhatsApp:** નહીં (અંદરનું).
**Mobile/PWA:** technician ના ફોનમાં.
**DB Tables:** `repair_kb` (vector/keyword index).
**APIs:** `POST ?r=ai_diagnose`.
**Security:** ગ્રાહકનું નામ **મોકલવું નહીં** — ફક્ત લક્ષણ; અંતિમ નિર્ણય technician નો.
**Complexity:** Hard
**Priority:** P1
**Monetization:** "AI Pro" / "Service Pro".
**Scalability:** index રાત્રે.
**Example:** "કાળી સ્ક્રીન" → 68% RAM, 22% ડિસ્પ્લે કેબલ, 10% પેનલ; અમારા 41 કેસ પરથી.

### #65 · AI Price Suggestion (નિયમની હદમાં) 🔴
**Category:** AI
**શું છે?** ભાવ **સૂચવે** — પણ floor/ceiling નિયમની બહાર કદી નહીં.
**શું કામ કરે છે?** ખર્ચ, હરીફના ભાવ (#20), માંગ, બેઠેલા દિવસ → સૂચન; `min_margin` થી નીચે **કદી નહીં**; અંતિમ ભાવ માણસ.
**Real-world Use Case:** ભાવ ક્યાં કાપી શકાય એની ખબર ન પડે → વધારે ડિસ્કાઉન્ટ.
**Customer:** વ્યાજબી ભાવ.
**Staff:** આધાર મળે; દલીલ ઘટે.
**Owner:** **માર્જિન સુરક્ષિત**; ભાવ નક્કી કરવાનું વિજ્ઞાન.
**Revenue Impact:** ઊંચી (1% ભાવ = સીધો નફો).
**Cost Saving:** મધ્યમ.
**Automation:** સૂચન.
**AI Opportunity:** ✔ — **સત્તા નહીં**.
**WhatsApp:** નહીં.
**Mobile/PWA:** બિલ સ્ક્રીન પર સૂચન.
**DB Tables:** `price_suggestions` (audit માટે).
**APIs:** `POST ?r=ai_price`.
**Security:** floor સર્વર પર; સૂચન સ્વીકારાય તો audit માં "AI સૂચન, માણસે મંજૂર".
**Complexity:** Medium
**Priority:** P1
**Monetization:** "AI Pro".
**Scalability:** cache.
**Example:** ખર્ચ ₹1,200 · હાલ ₹1,650 · 140 દિ બેઠું → સૂચન ₹1,450 (floor ₹1,320).

### #66 · AI Purchase Forecast (માણસ મંજૂર કરે) 🟡
**Category:** AI
**શું છે?** આવતા મહિને **શું અને કેટલું** ખરીદવું.
**શું કામ કરે છે?** `forecast` module છે (deterministic); AI એ ઉપર તહેવાર/નવું મોડેલ/સ્થાનિક કારણ ઉમેરે; #18 થી PO ડ્રાફ્ટ.
**Real-world Use Case:** દિવાળી પહેલાં શું ભરવું — અંદાજ પર.
**Customer:** માલ મળે.
**Staff:** યાદી તૈયાર.
**Owner:** **રોકડ સાચી જગ્યાએ**; ખૂટે નહીં, વધે નહીં.
**Revenue Impact:** ઊંચી.
**Cost Saving:** ઊંચી (dead stock ઘટે).
**Automation:** draft PO; મંજૂરી માણસની.
**AI Opportunity:** ✔ — **ગણતરી પહેલાં, AI સમજૂતી પછી**.
**WhatsApp:** માસિક સૂચન.
**Mobile/PWA:** જોવા/મંજૂર કરવા.
**DB Tables:** હાલનું `forecast` + `purchase_orders`.
**APIs:** `GET ?r=ai_purchase_plan`.
**Security:** PO બને ત્યારે જ સ્ટોક/પૈસાની અસર — AI થી નહીં.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "AI Pro".
**Scalability:** રાત્રે.
**Example:** "દિવાળી: કારતૂસ 40 (સામાન્ય 25), કીબોર્ડ 15 — કારણ: ગયા વર્ષે +58%".

### #67 · AI Customer Query Bot (કેટલોગ + હિસાબ) 🟡
**Category:** AI
**શું છે?** ગ્રાહકના સવાલનો WhatsApp પર **સાચો** જવાબ.
**શું કામ કરે છે?** `wa_bot.php` માં keyword બોટ છે; ન સમજાય એવા સવાલ માટે AI **પણ જવાબ ડેટાબેઝના તથ્ય પરથી** (ભાવ/સ્ટોક/બાકી query માંથી, AI થી નહીં).
**Real-world Use Case:** "HP કારતૂસ છે?" — રાત્રે 10 વાગ્યે કોઈ જવાબ આપતું નથી.
**Customer:** 24×7 જવાબ.
**Staff:** વારંવારના સવાલ ઘટે.
**Owner:** રાત્રે પણ સોદા પકડાય.
**Revenue Impact:** ઊંચી.
**Cost Saving:** ઊંચી.
**Automation:** ✔
**AI Opportunity:** ✔ ભાષા સમજવા — **આંકડા DB માંથી**.
**WhatsApp:** ✔ મુખ્ય.
**Mobile/PWA:** ચેટ ઇતિહાસ.
**DB Tables:** `wa_chats` (છે).
**APIs:** હાલની webhook.
**Security:** હિસાબ માટે **નંબર verify** (પહેલેથી છે); AI ને બીજા ગ્રાહકનો ડેટા કદી નહીં; ભાવ DB માંથી.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "AI Pro".
**Scalability:** cap + fallback (#62).
**Example:** "કારતૂસ 678 છે?" → "હા, 4 નંગ · ₹1,450 · દુકાન 9-9".

### #68 · AI Sales Insight (રોજનો ટૂંકો સાર) 🟡
**Category:** AI
**શું છે?** રોજ સવારે **3 લીટીમાં** ધ્યાન આપવા જેવી વાત.
**શું કામ કરે છે?** dashboard ના આંકડા (deterministic) → AI ટૂંકી ગુજરાતી ભાષામાં લખે; આંકડા AI બનાવે **નહીં**.
**Real-world Use Case:** માલિક રિપોર્ટ ખોલતો નથી; મેસેજ વાંચે છે.
**Customer:** —
**Staff:** —
**Owner:** **રોજ 30 સેકન્ડમાં દુકાનની હાલત**.
**Revenue Impact:** મધ્યમ (વહેલું ધ્યાન).
**Cost Saving:** મધ્યમ.
**Automation:** રોજ cron.
**AI Opportunity:** ✔ ફક્ત **લખવા માટે**.
**WhatsApp:** ✔ સવારે 9.
**Mobile/PWA:** notification.
**DB Tables:** કોઈ નવું નહીં.
**APIs:** `GET ?r=ai_brief`.
**Security:** આંકડા ગણતરીથી; AI પાસે ફક્ત સારાંશ જાય.
**Complexity:** Easy
**Priority:** P1
**Monetization:** "AI Pro".
**Scalability:** દિવસનો એક call.
**Example:** "કાલે ₹41,200 (+12%) · ઉઘરાણી ₹1.9L માં ₹42,000 90 દિ ઉપર · 3 વસ્તુ ખૂટવાની".

### #69 · AI Inventory Optimizer (કેટલું રાખવું) 🔴
**Category:** AI
**શું છે?** દરેક વસ્તુનું **સાચું લઘુત્તમ/મહત્તમ** સ્તર સૂચવે.
**શું કામ કરે છે?** વેચાણની ગતિ + lead time + મોસમ → #17 નું reorder point સુધારે; ABC વર્ગીકરણ.
**Real-world Use Case:** min level હાથે નાખેલા છે અને જૂના થઈ ગયા.
**Customer:** જે જોઈએ એ મળે.
**Staff:** alert સાચા (ખોટા નહીં).
**Owner:** **રોકડ 20-30% છૂટે** — સૌથી મોટો cash લાભ.
**Revenue Impact:** ઊંચી.
**Cost Saving:** **સૌથી ઊંચી**.
**Automation:** માસિક સૂચન → મંજૂરી.
**AI Opportunity:** ✔ સૂચન.
**WhatsApp:** માસિક સાર.
**Mobile/PWA:** જોવા.
**DB Tables:** `items.min_qty`, `items.max_qty`.
**APIs:** `GET ?r=ai_stock_levels`.
**Security:** માણસ મંજૂર કરે પછી જ કૉલમ બદલાય; audit.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "AI Pro".
**Scalability:** રાત્રે.
**Example:** "કારતૂસ 678: min 4→8, max 20→14 · ₹18,000 છૂટશે".

### #70 · AI Bill Reading (પુરવઠાનું બિલ ફોટામાંથી) 🔴
**Category:** AI
**શું છે?** પુરવઠાનું બિલ ફોટો પાડો → **purchase entry નો ડ્રાફ્ટ**.
**શું કામ કરે છે?** OCR + AI → વસ્તુ, નંગ, ભાવ, GST → **દરેક લીટી માણસ ચકાસે** → purchase.
**Real-world Use Case:** 30 લીટીનું બિલ ટાઇપ કરવામાં 25 મિનિટ.
**Customer:** —
**Staff:** **સૌથી કંટાળાજનક કામ ખતમ**.
**Owner:** entry ઝડપી = સ્ટોક સાચો, વહેલો.
**Revenue Impact:** પરોક્ષ (સાચો સ્ટોક = વેચાણ).
**Cost Saving:** **ઊંચી** (દિવસના 1-2 કલાક).
**Automation:** ડ્રાફ્ટ; મંજૂરી માણસની.
**AI Opportunity:** ✔ vision.
**WhatsApp:** બિલનો ફોટો WhatsApp પર મોકલો → ડ્રાફ્ટ.
**Mobile/PWA:** ✔ કેમેરા.
**DB Tables:** `purchase_drafts` (નવું).
**APIs:** `POST ?r=ai_bill_read`.
**Security:** **ડ્રાફ્ટ કદી આપોઆપ post ન થાય**; total મેળવ્યા વગર મંજૂરી નહીં; ફોટો uploads માં, બહાર નહીં.
**Complexity:** Hard
**Priority:** P2
**Monetization:** "AI Pro" — **સૌથી દેખીતો ફાયદો**.
**Scalability:** call દર બિલ 1.
**Example:** 30 લીટી, 28 સાચી, 2 શંકાસ્પદ પીળી → 3 મિનિટમાં પૂરું.

### #71 · AI Collection Priority & Message Tone 🟡
**Category:** AI
**શું છે?** આજે **કોને** અને **કેવી ભાષામાં** ઉઘરાણી કરવી.
**શું કામ કરે છે?** ચૂકવણીનો ઇતિહાસ + #26 health → ક્રમ; મેસેજની ભાષા નરમ/સામાન્ય/કડક; રકમ/બાકી **ledger માંથી**.
**Real-world Use Case:** સારા ગ્રાહકને કડક મેસેજ જાય → સંબંધ બગડે.
**Customer:** યોગ્ય ભાષા; સંબંધ સચવાય.
**Staff:** આજની યાદી તૈયાર.
**Owner:** **વસૂલાત વધે, સંબંધ સચવાય**.
**Revenue Impact:** **ઊંચી**.
**Cost Saving:** ઊંચી.
**Automation:** યાદી + મેસેજ ડ્રાફ્ટ; મોકલવું માણસ (કે મંજૂર કરેલી campaign).
**AI Opportunity:** ✔ ક્રમ + ભાષા.
**WhatsApp:** ✔
**Mobile/PWA:** ✔ યાદી.
**DB Tables:** હાલનું `collection_*`.
**APIs:** `GET ?r=ai_collection_plan`.
**Security:** રકમ **હંમેશા `party_balance()`** થી; AI આંકડો નહીં લખે.
**Complexity:** Medium
**Priority:** P0
**Monetization:** "AI Pro".
**Scalability:** રાત્રે.
**Example:** "આજે 8: 3 નરમ (જૂના સારા), 4 સામાન્ય, 1 કડક (3 વાર વચન તોડ્યું)".

### #72 · AI Anomaly Detection (કંઈક ખોટું છે) 🔴
**Category:** AI
**શું છે?** રોજના વ્યવહારમાં **અસામાન્ય** પકડે.
**શું કામ કરે છે?** આંકડાકીય મર્યાદા (z-score, નિયમ) **પહેલાં**, AI સમજૂતી પછી: અસામાન્ય ડિસ્કાઉન્ટ, રાત્રે entry, વારંવાર delete, ખર્ચનો ઉછાળો.
**Real-world Use Case:** સ્ટાફ રોજ ₹50-100 ડિસ્કાઉન્ટ આપે — મહિને ₹3,000 જાય, કોઈને ખબર ન પડે.
**Customer:** —
**Staff:** પ્રામાણિકતાનું વાતાવરણ (નિયમ સ્પષ્ટ).
**Owner:** **ગળતર પકડાય** — સૌથી છૂપું નુકસાન.
**Revenue Impact:** મધ્યમ-ઊંચી.
**Cost Saving:** **ઊંચી**.
**Automation:** રોજ alert.
**AI Opportunity:** ✔ — પણ **ગણતરી નિર્ણય લે**, AI ફક્ત સમજાવે.
**WhatsApp:** માલિકને ખાનગી.
**Mobile/PWA:** ✔
**DB Tables:** `anomalies` (નવું); `audit_logs` વાપરે.
**APIs:** `GET ?r=anomalies`.
**Security:** માલિક જ જુએ; આરોપ નહીં — **ધ્યાન દોરે**.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "AI Pro" / "Security".
**Scalability:** રાત્રે.
**Example:** "રમેશ: સરેરાશ ડિસ્કાઉન્ટ 4.1% (દુકાન 1.2%) · 22 બિલ · ₹2,840".

### #73 · AI Knowledge Base for Staff (દુકાનનો જવાબ) 🔴
**Category:** AI
**શું છે?** સ્ટાફ સવાલ પૂછે → **દુકાનના જ નિયમ/ઇતિહાસ** માંથી જવાબ.
**શું કામ કરે છે?** RAG: જૂના repairs, નોંધ, વોરંટી નિયમ, ભાવ નીતિ → "આ પ્રિન્ટરની વોરંટી કેટલી?" નો જવાબ.
**Real-world Use Case:** નવો છોકરો દર 10 મિનિટે માલિકને પૂછે.
**Customer:** ઝડપી સાચો જવાબ.
**Staff:** **જાતે શીખે**; પૂછવાની શરમ નહીં.
**Owner:** **માલિકનો સમય છૂટે** — સૌથી કીમતી સંપત્તિ.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** ઊંચી.
**Automation:** ✔
**AI Opportunity:** ✔ RAG.
**WhatsApp:** સ્ટાફ ગ્રુપમાં.
**Mobile/PWA:** ✔
**DB Tables:** `kb_docs`, `kb_chunks` (નવાં).
**APIs:** `POST ?r=ai_ask`.
**Security:** સ્ટાફની પરવાનગી પ્રમાણે — **જે ન જોઈ શકે એ જવાબમાં પણ નહીં** (ખર્ચ/નફો).
**Complexity:** Hard
**Priority:** P2
**Monetization:** "AI Pro".
**Scalability:** index રાત્રે.
**Example:** "HP પ્રિન્ટર વોરંટી?" → "1 વર્ષ onsite; કારતૂસ નહીં. અમારો નિયમ: પેટી સાચવવી."

### #74 · AI Product Description & Catalog Copy 🔴
**Category:** AI
**શું છે?** spec માંથી **વેચાણલાયક લખાણ** — ગુજરાતી + English.
**શું કામ કરે છે?** `item_specs` → 2 લીટીનું વર્ણન + મુખ્ય મુદ્દા; કેટલોગ/WhatsApp માટે.
**Real-world Use Case:** 800 વસ્તુના વર્ણન લખવા કોઈ બેસે નહીં → કેટલોગ સૂકો લાગે.
**Customer:** સમજાય એવું વર્ણન → વિશ્વાસ.
**Staff:** લખવાનું કામ નહીં.
**Owner:** **કેટલોગ વેચાણ કરે**, યાદી નહીં.
**Revenue Impact:** મધ્યમ-ઊંચી (ઓનલાઇન conversion).
**Cost Saving:** ઊંચી.
**Automation:** bulk; માણસ મંજૂર કરે.
**AI Opportunity:** ✔
**WhatsApp:** પ્રોડક્ટ મેસેજમાં.
**Mobile/PWA:** કેટલોગમાં.
**DB Tables:** `items.description` (છે).
**APIs:** `POST ?r=ai_describe`.
**Security:** **ખોટો દાવો નહીં** — spec માં જે છે એ જ; ભાવ/વોરંટીનું વચન AI ન લખે; **બનાવટી રિવ્યૂ કદી નહીં**.
**Complexity:** Easy
**Priority:** P2
**Monetization:** "Store Pro" / "AI Pro".
**Scalability:** bulk રાત્રે.
**Example:** "Dell 3520 — રોજના ઓફિસ કામ માટે. i3-1215U · 8GB · 512 SSD · 15.6" · 1 વર્ષ onsite."

---

## CATEGORY 13 — ACCOUNTING / FINANCE (4)

### #75 · e-Invoice (IRN) & e-Way Bill 🔴
**Category:** Accounting
**શું છે?** સરકારના પોર્ટલ પરથી **IRN + QR** અને ₹50,000 ઉપર e-way bill.
**શું કામ કરે છે?** GST બિલ → IRN API → IRN/QR બિલ પર છપાય; રદ થાય તો cancel.
**Real-world Use Case:** turnover મર્યાદા ઉપર જાય તો **કાયદેસર ફરજિયાત**.
**Customer:** માન્ય બિલ; ITC મળે.
**Staff:** પોર્ટલ પર અલગ entry નહીં.
**Owner:** **દંડ ટળે**; B2B ગ્રાહક ટકે.
**Revenue Impact:** ઊંચી (B2B માટે જરૂરી).
**Cost Saving:** ઊંચી (દંડ, ડબલ entry).
**Automation:** ✔
**AI Opportunity:** નથી — કાયદો deterministic.
**WhatsApp:** IRN સાથેનું PDF.
**Mobile/PWA:** સ્ટેટસ.
**DB Tables:** `einvoices` (નવું).
**APIs:** `POST ?r=irn_generate`, `POST ?r=irn_cancel`.
**Security:** GSP credentials **encrypted**; 24 કલાકનો cancel નિયમ; retry idempotent.
**Complexity:** Hard
**Priority:** P2 (મર્યાદા વટાવે તો **P0**)
**Monetization:** "GST Pro".
**Scalability:** queue + retry.
**Example:** INV-1042 → IRN ...8f2 + QR છપાયું.

### #76 · TDS / TCS Handling 🔴
**Category:** Accounting
**શું છે?** સરકારી/કંપની ગ્રાહક **TDS કાપે** — એની નોંધ.
**શું કામ કરે છે?** બિલ પર TDS %, કપાયેલી રકમ ledger માં અલગ ખાતે; 26AS મેળવણી માટે રિપોર્ટ.
**Real-world Use Case:** સરકારી ઓર્ડરમાં ₹1,000 કપાય — હિસાબમાં "બાકી" દેખાતું રહે.
**Customer:** સાચી entry.
**Staff:** બાકીની ખોટી ઉઘરાણી બંધ.
**Owner:** **ખાતું સાચું**; TDS credit મળે (અસલી પૈસા).
**Revenue Impact:** મધ્યમ (credit વસૂલ).
**Cost Saving:** ઊંચી (ખોટી ઉઘરાણી, CA ની મહેનત).
**Automation:** પાર્ટી પ્રમાણે TDS %.
**AI Opportunity:** નથી.
**WhatsApp:** નહીં.
**Mobile/PWA:** જોવા.
**DB Tables:** `party_ledger` માં `entry_type='tds'`.
**APIs:** `POST ?r=tds_entry`.
**Security:** ledger નિયમ પ્રમાણે; `accounts.edit`.
**Complexity:** Medium
**Priority:** P2
**Monetization:** "GST Pro".
**Scalability:** સાદું.
**Example:** ₹1,00,000 બિલ, 2% TDS ₹2,000 → બાકી ₹98,000, TDS credit ₹2,000.

### #77 · Fixed Assets & Depreciation 🔴
**Category:** Accounting
**શું છે?** દુકાનની પોતાની મિલકત (ટેસ્ટિંગ મશીન, ગાડી, ફર્નિચર) અને **ઘસારો**.
**શું કામ કરે છે?** asset રજિસ્ટર + WDV/SLM ઘસારો + વેચાણ પર નફો/નુકસાન.
**Real-world Use Case:** ₹60,000 ની ટેસ્ટિંગ મશીન — ખર્ચમાં આખી ગણાય તો નફો ખોટો.
**Customer:** —
**Staff:** —
**Owner:** **અસલી નફો**; CA ને તૈયાર આંકડો.
**Revenue Impact:** પરોક્ષ (કર બચત).
**Cost Saving:** ઊંચી (CA, કર).
**Automation:** વાર્ષિક ઘસારો.
**AI Opportunity:** નથી.
**WhatsApp:** નહીં.
**Mobile/PWA:** જોવા.
**DB Tables:** `assets`, `asset_depreciation` (નવાં).
**APIs:** `POST ?r=asset`.
**Security:** `accounts.edit`.
**Complexity:** Medium
**Priority:** P3
**Monetization:** "Accounting Pro".
**Scalability:** સાદું.
**Example:** મશીન ₹60,000 · 15% WDV · વર્ષ 1 ₹9,000 · WDV ₹51,000.

### #78 · Cash Flow Forecast (આવતા 30-90 દિવસ) 🟡
**Category:** Accounting
**શું છે?** આવતા મહિનામાં **કેટલા પૈસા આવશે અને જશે**.
**શું કામ કરે છે?** ઉઘરાણી (aging) + પુરવઠાનું દેવું (#23) + પગાર/ભાડું/EMI + AMC આવક → સપ્તાહવાર.
**Real-world Use Case:** "15 તા. પુરવઠાના ₹1.2L આપવાના છે — આવશે?" નો જવાબ નહીં.
**Customer:** —
**Staff:** —
**Owner:** **રોકડ ખૂટવાની આગોતરી ચેતવણી** — દુકાન બંધ પડવાનું #1 કારણ.
**Revenue Impact:** ઊંચી (સમયસર ખરીદી).
**Cost Saving:** **સૌથી ઊંચી** (વ્યાજ, દંડ, છેલ્લી ઘડીની ઉધારી).
**Automation:** સાપ્તાહિક.
**AI Opportunity:** કોણ ક્યારે ચૂકવશે એની શક્યતા — **ગણતરી પહેલાં**.
**WhatsApp:** સાપ્તાહિક સાર.
**Mobile/PWA:** ✔ ગ્રાફ.
**DB Tables:** `recurring_expenses` (નવું); બાકી હાલનું.
**APIs:** `GET ?r=cashflow`.
**Security:** માલિક જ.
**Complexity:** Medium
**Priority:** P0
**Monetization:** "Accounting Pro".
**Scalability:** cache.
**Example:** "સપ્તાહ 3: આવક ₹86,000 · ખર્ચ ₹1,34,000 → **₹48,000 ખૂટે**".

---
## CATEGORY 14 — STAFF / HR (4)

### #79 · Sales Target & Commission 🔴
**Category:** Staff/HR
**શું છે?** સ્ટાફ પ્રતિ **લક્ષ્ય** અને લક્ષ્ય પર **કમિશન** — આપોઆપ ગણાય.
**શું કામ કરે છે?** માસિક લક્ષ્ય (રકમ કે નફો) → `sales.user_id` પરથી પ્રગતિ → slab પ્રમાણે કમિશન → પગાર સાથે.
**Real-world Use Case:** સ્ટાફને ઉત્સાહ નથી; "કોણે કેટલું વેચ્યું" એ ચોપડે નથી.
**Customer:** સ્ટાફ ધ્યાન આપે.
**Staff:** **પોતાની કમાણી દેખાય** — સૌથી મોટી પ્રેરણા.
**Owner:** **વેચાણ વધે** પગાર વધાર્યા વગર; કમિશન નફા પર, વેચાણ પર નહીં (ડિસ્કાઉન્ટ ન વધે).
**Revenue Impact:** **ઊંચી**.
**Cost Saving:** —
**Automation:** ગણતરી આપોઆપ.
**AI Opportunity:** વ્યાજબી લક્ષ્ય સૂચવે.
**WhatsApp:** સાપ્તાહિક "તમે ₹X / ₹Y".
**Mobile/PWA:** ✔ પોતાનું કાર્ડ.
**DB Tables:** `staff_targets`, `staff_commission` (નવાં).
**APIs:** `GET ?r=my_target`.
**Security:** સ્ટાફ **પોતાનું જ** જુએ (`api_own_scope()` pattern); કમિશન `profit_cost_sql()` થી.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Staff Pro".
**Scalability:** સાદું.
**Example:** "રમેશ: ₹2.8L / ₹3L · નફો ₹41,000 · કમિશન ₹1,230".

### #80 · Attendance & Shift Roster 🔴
**Category:** Staff/HR
**શું છે?** હાજરી (ફોનથી, જગ્યા સાથે) અને **કોણ કઈ shift માં**.
**શું કામ કરે છે?** ફોનથી in/out + GPS (દુકાનની ત્રિજ્યા) → માસિક હાજરી → પગાર.
**Real-world Use Case:** હાજરી ચોપડે; પગાર વખતે દલીલ.
**Customer:** —
**Staff:** **દલીલ બંધ**; રજા/ઓવરટાઇમ સ્પષ્ટ.
**Owner:** પગારની ગણતરી સાચી; મોડું આવવું દેખાય.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** ઊંચી (પગારની ભૂલ, દલીલનો સમય).
**Automation:** માસિક શીટ.
**AI Opportunity:** નથી.
**WhatsApp:** ન આવ્યા હોય તો 11 વાગ્યે માલિકને.
**Mobile/PWA:** ✔ (#5 shift સાથે જોડાય).
**DB Tables:** `attendance` (નવું).
**APIs:** `POST ?r=punch`.
**Security:** GPS ત્રિજ્યા; બીજાની હાજરી નહીં; સુધારો audit સાથે.
**Complexity:** Medium
**Priority:** P2
**Monetization:** "Staff Pro".
**Scalability:** સાદું.
**Example:** "રમેશ 26 દિ · 2 રજા · 3 વાર મોડું".

### #81 · Staff Productivity (સેલ્સ + સર્વિસ) 🟡
**Category:** Staff/HR
**શું છે?** કોણ **કેટલું કામ** કરે છે — વેચાણ, job, સરેરાશ બિલ, પરત.
**શું કામ કરે છે?** `sales.user_id`, `repairs.technician_id`, `audit_logs` → એક પાનું.
**Real-world Use Case:** કોણ ખરેખર કમાવે છે એ માત્ર અંદાજ છે.
**Customer:** સારો સ્ટાફ ટકે.
**Staff:** મહેનત દેખાય.
**Owner:** **પગાર/બોનસના સાચા નિર્ણય**; તાલીમ કોને જોઈએ.
**Revenue Impact:** મધ્યમ-ઊંચી.
**Cost Saving:** ઊંચી.
**Automation:** માસિક.
**AI Opportunity:** સુધારાનું સૂચન.
**WhatsApp:** માસિક માલિકને.
**Mobile/PWA:** ✔
**DB Tables:** કોઈ નવું નહીં.
**APIs:** `GET ?r=staff_perf`.
**Security:** માલિક જ બધાનું; સ્ટાફ પોતાનું.
**Complexity:** Easy
**Priority:** P1
**Monetization:** "Staff Pro".
**Scalability:** cache.
**Example:** "રમેશ 142 બિલ · સરેરાશ ₹2,100 · નફો 14% · પરત 1 · Job 22 (સરેરાશ 1.6 દિ)".

### #82 · Training & Certification Tracker 🔴
**Category:** Staff/HR
**શું છે?** સ્ટાફ **શું શીખ્યો** અને શું બાકી — brand ની તાલીમ, certificate.
**શું કામ કરે છે?** કૌશલ્ય યાદી + તાલીમ નોંધ + certificate expiry; કૌશલ્ય પ્રમાણે job સોંપણી (#40).
**Real-world Use Case:** "લેપટોપ mainboard કોણ કરી શકે?" — યાદ પર આધાર.
**Customer:** સાચો માણસ આવે.
**Staff:** કારકિર્દીનો રસ્તો દેખાય.
**Owner:** **જ્ઞાન એક જ માણસમાં કેદ ન રહે** — એ જાય તો દુકાન અટકે નહીં.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** મધ્યમ.
**Automation:** expiry રિમાઇન્ડર.
**AI Opportunity:** #73 માંથી શું શીખવવું એ સૂચવે.
**WhatsApp:** certificate expiry.
**Mobile/PWA:** જોવા.
**DB Tables:** `staff_skills`, `staff_training` (નવાં).
**APIs:** `GET ?r=skills`.
**Security:** `users.edit`.
**Complexity:** Easy
**Priority:** P3
**Monetization:** "Staff Pro".
**Scalability:** સાદું.
**Example:** "રમેશ: લેપટોપ L2 ✔ · CCTV L1 ✔ · પ્રિન્ટર ✖ → તાલીમ".

---

## CATEGORY 15 — B2B / CORPORATE (3)

### #83 · Corporate Account: PO, Contract Rate, Monthly Bill 🟡
**Category:** B2B
**શું છે?** કંપની ગ્રાહકનું **કરારનું ભાવપત્રક**, તેમનો PO નંબર, અને **મહિને એક બિલ**.
**શું કામ કરે છે?** પાર્ટી પ્રતિ ભાવપત્રક (`party_prices` નવું) + બિલ પર તેમનો PO નંબર ફરજિયાત + મહિનાના challan એક બિલમાં.
**Real-world Use Case:** હોટેલ/શાળા રોજ નાની ખરીદી કરે; મહિને એક બિલ માંગે અને PO નંબર વગર પૈસા ન આપે.
**Customer:** તેમની પ્રક્રિયા પ્રમાણે — **તેથી જ મોટા ગ્રાહક ટકે**.
**Staff:** ભાવ યાદ રાખવા ન પડે.
**Owner:** **મોટા સ્થિર ગ્રાહક**; ઉઘરાણી અટકે નહીં (PO નંબર છે).
**Revenue Impact:** **ઊંચી** (મોટી, પુનરાવર્તિત).
**Cost Saving:** ઊંચી (ઉઘરાણીના ધક્કા).
**Automation:** માસિક બિલ આપોઆપ.
**AI Opportunity:** નથી.
**WhatsApp:** માસિક બિલ + statement.
**Mobile/PWA:** ✔
**DB Tables:** `party_prices`, `sales.po_no` (નવાં).
**APIs:** `GET ?r=party_price`, `POST ?r=monthly_bill`.
**Security:** ભાવપત્રક સર્વર પર; floor નીચે નહીં; `party.edit`.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "B2B Pro".
**Scalability:** સાદું.
**Example:** "Hotel Sagar: કારતૂસ ₹1,380 (કરાર) · PO/2026/88 · મહિનાનું બિલ ₹42,600".

### #84 · Tender / Rate Contract Quotation 🔴
**Category:** B2B
**શું છે?** સરકારી/કંપની **ટેન્ડર** માટેનું ફોર્મેટ પ્રમાણેનું ક્વોટેશન.
**શું કામ કરે છે?** ટેન્ડર નંબર, વસ્તુવાર ભાવ (GST અલગ/સહિત), માન્યતા, શરતો, technical specs; Excel/PDF.
**Real-world Use Case:** શાળા-પંચાયતના ટેન્ડર — ફોર્મેટ ખોટું હોય તો બિડ રદ.
**Customer:** માન્ય બિડ.
**Staff:** ટાઇપ કરવાનું કામ ઘટે.
**Owner:** **મોટા ઓર્ડરની તક**; ફોર્મેટની ભૂલથી બિડ ન જાય.
**Revenue Impact:** **ઊંચી** (ટિકિટ મોટી).
**Cost Saving:** મધ્યમ.
**Automation:** ફોર્મેટ.
**AI Opportunity:** ટેન્ડરના PDF માંથી વસ્તુની યાદી વાંચે — **માણસ ચકાસે**.
**WhatsApp:** નહીં (ઈમેલ/છાપેલું).
**Mobile/PWA:** જોવા.
**DB Tables:** `tenders` (નવું); #29 quotation વાપરે.
**APIs:** `POST ?r=tender_quote`.
**Security:** margin floor; બિડ મોકલ્યા પછી lock (audit).
**Complexity:** Medium
**Priority:** P3
**Monetization:** "B2B Pro".
**Scalability:** સાદું.
**Example:** "TENDER/2026/17 — 25 PC, 3 વર્ષ વોરંટી, ₹11.2L".

### #85 · Dealer / Reseller Portal with Credit Limit 🟡
**Category:** B2B
**શું છે?** ડીલર **જાતે ઓર્ડર** કરે — તેમનો ભાવ, તેમની credit મર્યાદા.
**શું કામ કરે છે?** હાલનું dealer login + #83 ભાવપત્રક + credit limit ચેક; મર્યાદા વટે તો ઓર્ડર **રોકાય**, મંજૂરી માંગે.
**Real-world Use Case:** નાના ડીલર WhatsApp પર ઓર્ડર કરે; બાકી વધી જાય અને માલ જતો રહે.
**Customer (ડીલર):** 24×7 ઓર્ડર, ભાવ-સ્ટોક દેખાય.
**Staff:** ઓર્ડર લખવાનું કામ નહીં.
**Owner:** **ઉધારીનું જોખમ નિયંત્રણમાં**; ડીલર વેચાણ વધે.
**Revenue Impact:** **ઊંચી**.
**Cost Saving:** **ઊંચી** (ખરાબ ઉધારી).
**Automation:** મર્યાદા ચેક.
**AI Opportunity:** ડીલરની મર્યાદા સૂચવે (ઇતિહાસ પરથી).
**WhatsApp:** ઓર્ડરની પુષ્ટિ.
**Mobile/PWA:** ✔ (એપમાં dealer mode છે).
**DB Tables:** `parties.credit_limit` (નવો કૉલમ).
**APIs:** `POST ?r=worder` માં limit ચેક.
**Security:** મર્યાદા **સર્વર પર**; `party_balance()` થી બાકી; bypass નહીં.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "B2B Pro".
**Scalability:** ઓર્ડર પ્રતિ એક ચેક.
**Example:** "ડીલર બાકી ₹48,000 / મર્યાદા ₹50,000 → ₹12,000 નો ઓર્ડર **મંજૂરી માટે**".

---

## CATEGORY 16 — MULTI-BRANCH / MULTI-LOCATION (3)

### #86 · Branch P&L and Comparison 🟡
**Category:** Multi-branch
**શું છે?** **દરેક શાખાનો પોતાનો નફો** — સરખામણી સાથે.
**શું કામ કરે છે?** `locations` છે; વેચાણ/ખરીદી/ખર્ચ/પગાર શાખાવાર → શાખા પ્રતિ P&L.
**Real-world Use Case:** બીજી દુકાન ખોલી — ખરેખર કમાય છે કે નહીં એ ખબર નથી.
**Customer:** —
**Staff:** શાખાની જવાબદારી સ્પષ્ટ.
**Owner:** **કઈ શાખા ખોટમાં** — બંધ કરવી કે સુધારવી એ નિર્ણય.
**Revenue Impact:** ઊંચી.
**Cost Saving:** **સૌથી ઊંચી** (ખોટવાળી શાખા).
**Automation:** માસિક.
**AI Opportunity:** ફરક કેમ છે એની સમજૂતી.
**WhatsApp:** માસિક.
**Mobile/PWA:** ✔
**DB Tables:** `expenses.location_id` (કૉલમ).
**APIs:** `GET ?r=branch_pl`.
**Security:** માલિક બધું; મેનેજર પોતાની શાખા.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Multi-branch".
**Scalability:** cache.
**Example:** "દ્વારકા ₹1.42L નફો · ખંભાળિયા ₹18,000 ખોટ (ભાડું ઊંચું)".

### #87 · Stock Transfer with In-Transit & Approval 🟡
**Category:** Multi-branch
**શું છે?** શાખા વચ્ચે માલ **રસ્તામાં** પણ દેખાય; મળ્યાની પુષ્ટિ પછી જ ઉમેરાય.
**શું કામ કરે છે?** `stock_transfers` છે; in-transit સ્થિતિ + મળ્યાની પુષ્ટિ + ફરક (ઘટ) નોંધ.
**Real-world Use Case:** માલ મોકલ્યો, પહોંચ્યો નહીં — બંને શાખા બીજા પર દોષ મૂકે.
**Customer:** —
**Staff:** જવાબદારી સ્પષ્ટ.
**Owner:** **રસ્તામાં ખોવાતો માલ પકડાય**.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** ઊંચી.
**Automation:** પુષ્ટિ રિમાઇન્ડર.
**AI Opportunity:** નથી.
**WhatsApp:** "3 વસ્તુ મોકલી — મળ્યે પુષ્ટિ કરો".
**Mobile/PWA:** ✔ પુષ્ટિ ફોનથી.
**DB Tables:** `stock_transfers.status` (કૉલમ).
**APIs:** `POST ?r=transfer_receive`.
**Security:** સ્ટોક ફક્ત `adjust_stock()` થી; ઘટ = audit સાથે અલગ entry.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Multi-branch".
**Scalability:** સાદું.
**Example:** "TR-88: 3 મોકલી, 2 મળી → 1 ની તપાસ".

### #88 · Central Purchase, Branch Distribution 🔴
**Category:** Multi-branch
**શું છે?** **એક જ જગ્યાએથી ખરીદી** (ભાવ સારો મળે) પછી શાખાઓમાં વહેંચણી.
**શું કામ કરે છે?** શાખાની જરૂર (#17/#69) જોડાઈને એક PO (#15) → માલ આવે → સૂચવેલી વહેંચણી → #87 થી transfer.
**Real-world Use Case:** બે શાખા અલગ અલગ 10-10 ખરીદે; 20 એકસાથે લેતાં ભાવ 4% સસ્તો.
**Customer:** ભાવ સ્પર્ધાત્મક.
**Staff:** ખરીદીની મહેનત એક જ વાર.
**Owner:** **ખરીદ ભાવ સીધો ઘટે** — 100% નફામાં જાય.
**Revenue Impact:** ઊંચી.
**Cost Saving:** **સૌથી ઊંચી**.
**Automation:** જરૂર જોડાય + વહેંચણી સૂચવાય.
**AI Opportunity:** વહેંચણીનું પ્રમાણ સૂચવે.
**WhatsApp:** શાખાને "આટલું આવશે".
**Mobile/PWA:** મંજૂરી.
**DB Tables:** `purchase_orders` (#15) + `po_allocations` (નવું).
**APIs:** `POST ?r=po_allocate`.
**Security:** માલિક/ખરીદી પરવાનગી; સ્ટોક `adjust_stock()` થી.
**Complexity:** Hard
**Priority:** P2
**Monetization:** "Multi-branch".
**Scalability:** સાદું.
**Example:** "કારતૂસ 20 (દ્વારકા 12, ખંભાળિયા 8) → ભાવ ₹1,180 (₹1,230 ને બદલે) = ₹1,000 બચત".

---

## CATEGORY 17 — SECURITY / COMPLIANCE (3)

### #89 · Two-Factor Login & Device Trust 🔴
**Category:** Security
**શું છે?** પાસવર્ડ + **OTP/TOTP**; ભરોસાનાં ઉપકરણ યાદ રહે.
**શું કામ કરે છે?** માલિક/હિસાબની પરવાનગી વાળા માટે 2FA ફરજિયાત; નવું ઉપકરણ = OTP; ઉપકરણની યાદી + દૂર કરવાની સગવડ.
**Real-world Use Case:** પાસવર્ડ સ્ટાફને ખબર પડે તો **બધો હિસાબ ખુલ્લો**.
**Customer:** તેમનો ડેટા સુરક્ષિત.
**Staff:** —
**Owner:** **ધંધાની તિજોરીને બીજું તાળું**.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** **સૌથી ઊંચી** (એક ભંગ = આખો ડેટા).
**Automation:** નવા ઉપકરણનું alert.
**AI Opportunity:** નથી.
**WhatsApp:** OTP + "નવા ફોનથી લોગિન".
**Mobile/PWA:** ✔ ઉપકરણ યાદ.
**DB Tables:** `user_devices`, `user_totp` (નવાં).
**APIs:** `POST ?r=login_2fa`.
**Security:** secret **encrypted**; throttle (પહેલેથી છે); recovery code.
**Complexity:** Medium
**Priority:** P1
**Monetization:** core.
**Scalability:** સાદું.
**Example:** "નવા ફોનથી લોગિન → OTP → 'આ ઉપકરણ યાદ રાખો (30 દિ)'".

### #90 · Encrypted Backup & Restore Drill 🟡
**Category:** Security
**શું છે?** રોજનો **encrypted** બેકઅપ + મહિને એક વાર **ખરેખર પરત લાવીને** ચકાસવું.
**શું કામ કરે છે?** `backup.php` છે; encryption + બહાર નકલ + restore કરીને "ચાલ્યું" નો પુરાવો.
**Real-world Use Case:** બેકઅપ લેવાય છે — પણ ખૂલે છે કે નહીં એ કોઈએ કદી ચકાસ્યું નથી.
**Customer:** ડેટા ખોવાય નહીં.
**Staff:** —
**Owner:** **ધંધો ખતમ થવાનું જોખમ** ખતમ; બેકઅપ ચોરાય તો પણ વંચાય નહીં.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** **અમાપ** (ડેટા ગયો = ધંધો ગયો).
**Automation:** રોજ + માસિક drill.
**AI Opportunity:** નથી.
**WhatsApp:** "બેકઅપ ✔ 42MB · restore ચકાસ્યું 1 તા."
**Mobile/PWA:** સ્થિતિ.
**DB Tables:** `backup_log` (નવું).
**APIs:** `GET ?r=backup_status`.
**Security:** key **સર્વર પર નહીં** (માલિક પાસે); બેકઅપ ફાઇલ web થી ન ખૂલે.
**Complexity:** Medium
**Priority:** **P0**
**Monetization:** core.
**Scalability:** સાદું.
**Example:** "28 દિ સળંગ ✔ · છેલ્લું restore-test 1 તા. સફળ (18 સેકન્ડ)".

### #91 · Data Privacy, Consent & Deletion Request 🟡
**Category:** Security
**શું છે?** ગ્રાહકની **સંમતિ** નોંધાયેલી; માંગે તો **ડેટા આપો / ભૂંસો**.
**શું કામ કરે છે?** campaign માટે opt-in/opt-out (`cam_is_stop_word()` છે) + ગ્રાહકનો ડેટા export + કાયદેસર જરૂરી ન હોય તે ભૂંસવું (બિલ કાયદા મુજબ રહે, નંબર/નામ anonymise).
**Real-world Use Case:** DPDP કાયદો; અને "મને મેસેજ ન મોકલો" નું માન.
**Customer:** **તેમની પસંદગીનું માન** — વિશ્વાસનું મૂળ.
**Staff:** નિયમ સ્પષ્ટ.
**Owner:** **કાયદેસર સુરક્ષા**; ફરિયાદનું જોખમ ઘટે.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** ઊંચી (દંડ).
**Automation:** opt-out તરત અમલમાં.
**AI Opportunity:** નથી — અને **AI ને ગ્રાહકનો ડેટા જરૂર વગર નહીં**.
**WhatsApp:** "બંધ" લખો = બંધ (ગુજરાતી/હિન્દી/English).
**Mobile/PWA:** સેટિંગમાં.
**DB Tables:** `party_consent` (નવું); `wa_optout` (છે).
**APIs:** `POST ?r=consent`, `GET ?r=my_data`.
**Security:** ભૂંસવાનું audit સાથે; કાયદેસર રેકોર્ડ ન ભૂંસાય — anonymise.
**Complexity:** Medium
**Priority:** P1
**Monetization:** core.
**Scalability:** સાદું.
**Example:** "ગ્રાહકે 'બંધ' લખ્યું → campaign બંધ, બિલ/વોરંટીના મેસેજ ચાલુ (એ સેવા છે)".

---

## CATEGORY 18 — ANALYTICS / BI (4)

### #92 · Margin Leak Report (નફો ક્યાં ગળે છે) 🔴
**Category:** Analytics
**શું છે?** ધારેલો નફો અને **અસલી** નફો — વચ્ચેનો ફરક ક્યાં ગયો.
**શું કામ કરે છે?** વસ્તુવાર: ડિસ્કાઉન્ટ + પરત + વોરંટી ખર્ચ (#37) + freight + ઘટ → "ગળતરનો નકશો".
**Real-world Use Case:** વેચાણ સારું છે પણ પૈસા બચતા નથી.
**Customer:** —
**Staff:** કઈ આદત નુકસાન કરે એ દેખાય.
**Owner:** **અસલી નફો**; એક જગ્યાએ સુધારો = સીધો નફો.
**Revenue Impact:** **ઊંચી**.
**Cost Saving:** **સૌથી ઊંચી**.
**Automation:** માસિક.
**AI Opportunity:** "સૌથી પહેલાં આ સુધારો" — 3 સૂચન.
**WhatsApp:** માસિક.
**Mobile/PWA:** ✔
**DB Tables:** કોઈ નવું નહીં (`profit_cost_sql()` વાપરે).
**APIs:** `GET ?r=margin_leak`.
**Security:** માલિક જ (ખર્ચ દેખાય).
**Complexity:** Medium
**Priority:** P0
**Monetization:** "Analytics Pro".
**Scalability:** cache.
**Example:** "ધાર્યો 18.2% · અસલી 13.4% → ડિસ્કાઉન્ટ 2.1% · વોરંટી 1.4% · પરત 0.8% · ઘટ 0.5%".

### #93 · Customer Lifetime Value & Segments 🔴
**Category:** Analytics
**શું છે?** ગ્રાહકે **જીવનભરમાં** કેટલો નફો આપ્યો; વર્ગ પ્રમાણે વહેંચણી.
**શું કામ કરે છે?** RFM (છેલ્લી ખરીદી, વારંવારતા, રકમ) + નફો → VIP / સામાન્ય / જોખમી / ગુમાવેલા.
**Real-world Use Case:** "મોટા ગ્રાહક" એ યાદ પર નક્કી થાય છે; ઘણી વાર ખોટું.
**Customer:** VIP ને સાચી કિંમત મળે.
**Staff:** કોને ધ્યાન આપવું એ સ્પષ્ટ.
**Owner:** **માર્કેટિંગ ખર્ચ સાચી જગ્યાએ**; 20% ગ્રાહક 80% નફો.
**Revenue Impact:** **ઊંચી**.
**Cost Saving:** ઊંચી (નકામું માર્કેટિંગ).
**Automation:** માસિક વર્ગીકરણ.
**AI Opportunity:** કોણ જવાની તૈયારીમાં (#26).
**WhatsApp:** વર્ગ પ્રમાણે campaign.
**Mobile/PWA:** ✔
**DB Tables:** `parties.segment` (કૉલમ).
**APIs:** `GET ?r=clv`.
**Security:** માલિક; campaign માં consent (#91) જરૂરી.
**Complexity:** Medium
**Priority:** P1
**Monetization:** "Analytics Pro".
**Scalability:** રાત્રે.
**Example:** "VIP 42 ગ્રાહક = 61% નફો · જોખમી 18 (₹2.1L નફો જોખમમાં)".

### #94 · Owner Dashboard on Phone (એક સ્ક્રીન) 🟡
**Category:** Analytics
**શું છે?** માલિક માટે **એક જ સ્ક્રીન** — આજ, રોકડ, ઉઘરાણી, સ્ટોક, ધ્યાન આપવા જેવું.
**શું કામ કરે છે?** હાલનું dashboard મોબાઇલ માટે ફરી ગોઠવવું; 6 કાર્ડ, બસ.
**Real-world Use Case:** માલિક બહાર હોય ત્યારે દુકાનની હાલત જાણવી.
**Customer:** —
**Staff:** —
**Owner:** **ગમે ત્યાંથી નિયંત્રણ**; રિપોર્ટ ખોલવાની જરૂર નહીં.
**Revenue Impact:** પરોક્ષ (વહેલા નિર્ણય).
**Cost Saving:** સમય.
**Automation:** cache થી ઝડપી.
**AI Opportunity:** #68 નો સાર ઉપર.
**WhatsApp:** રોજનો સાર.
**Mobile/PWA:** ✔ મુખ્ય.
**DB Tables:** કોઈ નવું નહીં.
**APIs:** `GET ?r=dash`.
**Security:** માલિકની પરવાનગી; cache no-store.
**Complexity:** Easy
**Priority:** P1
**Monetization:** core.
**Scalability:** file cache (છે).
**Example:** "આજ ₹18,400 · રોકડ ₹9,200 · ઉઘરાણી ₹1.9L · 3 વસ્તુ ખૂટવાની · 2 job અટક્યાં".

### #95 · Custom Report Builder + Scheduled Email/WhatsApp 🔴
**Category:** Analytics
**શું છે?** પોતાનો રિપોર્ટ **જાતે બનાવો** અને નિયમિત મેળવો.
**શું કામ કરે છે?** ક્ષેત્ર + ગાળણ + સમૂહ પસંદ કરો → સાચવો → રોજ/સપ્તાહ/મહિને આપોઆપ મળે (PDF/Excel).
**Real-world Use Case:** માલિકને જોઈતો ચોક્કસ કાગળ દર વખતે હાથે બનાવવો પડે.
**Customer:** —
**Staff:** વારંવારની માંગ બંધ.
**Owner:** **પોતાની રીતે** ધંધો જોવો.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** ઊંચી (સમય).
**Automation:** schedule.
**AI Opportunity:** "ગુજરાતીમાં પૂછો, રિપોર્ટ બને" — પણ **query નિયમથી બને**, AI SQL ન લખે.
**WhatsApp:** ✔ નિયત સમયે.
**Mobile/PWA:** જોવા.
**DB Tables:** `saved_reports` (નવું).
**APIs:** `POST ?r=report_save`, `GET ?r=report_run`.
**Security:** **whitelisted ક્ષેત્ર જ** (કાચું SQL કદી નહીં); પરવાનગી પ્રમાણે ક્ષેત્ર મર્યાદિત.
**Complexity:** Hard
**Priority:** P2
**Monetization:** "Analytics Pro".
**Scalability:** queue.
**Example:** "દર સોમવારે 9 વાગ્યે: 'ગયા સપ્તાહનું CCTV વેચાણ' WhatsApp પર".

---

## CATEGORY 19 — AUTOMATION / WORKFLOW (3)

### #96 · Job Queue & Retry (પાયાનું) 🔴
**Category:** Automation
**શું છે?** ધીમાં કામ (WhatsApp, PDF, IRN, AI) **પાછળ** ચાલે — retry સાથે.
**શું કામ કરે છે?** `jobs` કોષ્ટક + `cron.php` worker; નિષ્ફળ થાય તો exponential backoff; dead-letter.
**Real-world Use Case:** બિલ સાચવતી વખતે WhatsApp ધીમું હોય તો સ્ક્રીન અટકે — ગ્રાહક લાઇનમાં.
**Customer:** **બિલ તરત** — બાકીનું પાછળ.
**Staff:** સ્ક્રીન અટકે નહીં.
**Owner:** **મેસેજ ખોવાય નહીં**; સિસ્ટમ ઝડપી.
**Revenue Impact:** પરોક્ષ (કાઉન્ટરની ઝડપ).
**Cost Saving:** ઊંચી.
**Automation:** ✔ પાયો.
**AI Opportunity:** નથી.
**WhatsApp:** આ એની જ નળી.
**Mobile/PWA:** આ જ pattern outbox માં છે.
**DB Tables:** `jobs`, `jobs_dead` (નવાં).
**APIs:** અંદરનું; `GET ?r=jobs_health`.
**Security:** idempotency key (એક જ મેસેજ બે વાર નહીં); payload માં secret નહીં.
**Complexity:** Medium
**Priority:** **P0 — #58/#61/#70/#75 આના પર ઊભાં**
**Monetization:** core પાયો.
**Scalability:** **આ જ scalability નો પાયો**.
**Example:** "કતાર 3 · નિષ્ફળ 0 · છેલ્લું worker 40 સેકન્ડ પહેલાં".

### #97 · Rule Engine: "આવું થાય તો આવું કરો" 🔴
**Category:** Automation
**શું છે?** માલિક **જાતે નિયમ** બનાવે — કોડ વગર.
**શું કામ કરે છે?** ઘટના (બિલ બન્યું, સ્ટોક ઘટ્યો, બાકી 30 દિ) + શરત → ક્રિયા (WhatsApp, task, alert). ક્રિયાઓ **પહેલેથી મંજૂર કરેલી** યાદીમાંથી જ.
**Real-world Use Case:** "₹10,000 ઉપરનું બિલ થાય તો મને મેસેજ" — આજે એ માટે કોડ બદલવો પડે.
**Customer:** સુસંગત સેવા.
**Staff:** યાદ રાખવાનું ઘટે.
**Owner:** **પોતાની રીતે દુકાન ચલાવે**; નવી જરૂર માટે વિકાસકાર્ય નહીં.
**Revenue Impact:** મધ્યમ-ઊંચી.
**Cost Saving:** ઊંચી.
**Automation:** ✔ મુખ્ય.
**AI Opportunity:** ગુજરાતીમાં લખો → નિયમ સૂચવે (**માણસ મંજૂર કરે**).
**WhatsApp:** ક્રિયા તરીકે.
**Mobile/PWA:** જોવા.
**DB Tables:** `rules`, `rule_runs` (નવાં).
**APIs:** `POST ?r=rule`.
**Security:** ક્રિયા **whitelist** માંથી જ; પૈસા/સ્ટોક બદલતી ક્રિયા **નહીં** (ફક્ત સૂચના/task); દરેક run audit.
**Complexity:** Hard
**Priority:** P2
**Monetization:** "Automation Pro".
**Scalability:** #96 ની કતાર વાપરે.
**Example:** "બાકી > ₹20,000 અને 45 દિ → માલિકને મેસેજ + ઉઘરાણી task".

### #98 · Day-end Auto Close & Checklist 🟡
**Category:** Automation
**શું છે?** દિવસના અંતે **આપોઆપ સરવાળો** અને શું બાકી છે એની યાદી.
**શું કામ કરે છે?** #5 shift handover + રોકડ મેળવણી + અધૂરાં બિલ + અધૂરાં job + બેકઅપ ✔ → એક પાનું; માલિકને WhatsApp.
**Real-world Use Case:** રાત્રે દુકાન બંધ કરતી વખતે કંઈક ને કંઈક ભૂલાય.
**Customer:** —
**Staff:** **બંધ કરવાની સ્પષ્ટ યાદી**.
**Owner:** **રોજ દુકાન સાફ બંધ થાય**; સવારે આશ્ચર્ય નહીં.
**Revenue Impact:** પરોક્ષ.
**Cost Saving:** ઊંચી (રોકડનો ફરક).
**Automation:** ✔
**AI Opportunity:** #68 નો સાર.
**WhatsApp:** ✔ રાત્રે.
**Mobile/PWA:** ✔
**DB Tables:** `day_close` (નવું).
**APIs:** `POST ?r=day_close`.
**Security:** બંધ કર્યા પછીના ફેરફાર audit સાથે.
**Complexity:** Easy
**Priority:** P1
**Monetization:** core.
**Scalability:** સાદું.
**Example:** "વેચાણ ₹41,200 · રોકડ મળી ✔ · 1 બિલ અધૂરું · 2 job · બેકઅપ ✔".

---

## CATEGORY 20 — ADVANCED / ENTERPRISE (2)

### #99 · PC Builder Public Configurator (ગ્રાહક જાતે બનાવે) 🔴 · PC Builder મોડ્યુલ 5/5
**Category:** Advanced
**શું છે?** ગ્રાહક **વેબસાઈટ પર જાતે** PC બનાવે — ભાવ સાથે, અને ઓર્ડર કરે.
**શું કામ કરે છે?** #30 compatibility + #31 PSU + સ્ટોક + ભાવ **સર્વર પર** → "ઓર્ડર કરો" → #29 ક્વોટ/ઓર્ડર; ન ચાલે એવું જોડાણ પસંદ **થઈ જ ન શકે**.
**Real-world Use Case:** યુવાન ગ્રાહક જાતે ગોઠવવાનું પસંદ કરે; આજે એ ગ્રાહક બહારની વેબસાઈટ પર જાય છે.
**Customer:** **જાતે બનાવવાની મજા** + ભરોસો કે ચાલશે.
**Staff:** તૈયાર યાદી સાથે ગ્રાહક આવે.
**Owner:** **નવો, ઊંચી ટિકિટનો ગ્રાહક વર્ગ**; આ એક ફીચર દુકાનને ઓનલાઇન સ્પર્ધામાં લાવે.
**Revenue Impact:** **સૌથી ઊંચી**.
**Cost Saving:** ઊંચી (સેલ્સનો સમય).
**Automation:** આખું.
**AI Opportunity:** #63 "બજેટ કહો" ગ્રાહકને પણ — સૂચન માત્ર.
**WhatsApp:** બનાવેલું PC WhatsApp પર મોકલો.
**Mobile/PWA:** ✔
**DB Tables:** `pc_builds` (#29/#63 સાથે એક જ).
**APIs:** `GET ?r=build_parts`, `POST ?r=build_price` (rate-limited, જાહેર).
**Security:** **ભાવ અને સ્ટોક સર્વર પર જ** (ગ્રાહકનું browser વિશ્વાસપાત્ર નહીં); floor નીચે ભાવ અશક્ય; rate limit.
**Complexity:** Hard
**Priority:** P2
**Monetization:** **"Store Pro" નું મુખ્ય આકર્ષણ**.
**Scalability:** parts cache; ભાવની ગણતરી સર્વર.
**Example:** ગ્રાહકે ₹52,000 નું gaming PC બનાવ્યું → PSU ચેતવણી → 550W સૂચવ્યું → ઓર્ડર.

### #100 · Multi-Tenant SaaS: બીજી દુકાનોને વેચવું 🔴
**Category:** Advanced
**શું છે?** આ **આખી સિસ્ટમ બીજી કમ્પ્યુટર દુકાનોને** ભાડે આપવી.
**શું કામ કરે છે?** `tenant_id` દરેક કોષ્ટકમાં + subdomain + પેકેજ/બિલિંગ + tenant પ્રતિ સેટિંગ/બ્રાન્ડિંગ + વપરાશની મર્યાદા. એક જ કોડ, દરેક દુકાનનો પોતાનો ડેટા (અથવા DB-per-tenant).
**Real-world Use Case:** દ્વારકા-જામનગરની 50 કમ્પ્યુટર દુકાનોને આ જ જરૂર છે; Vyapar/Marg તેમના ધંધા માટે બન્યાં નથી.
**Customer (દુકાનદાર):** પોતાના ધંધા માટે બનેલું સોફ્ટવેર.
**Staff:** —
**Owner:** **દુકાનની આવક ઉપરાંત સોફ્ટવેરની પુનરાવર્તિત આવક** — ધંધાનું સ્વરૂપ બદલાય.
**Revenue Impact:** **સૌથી ઊંચી** — 50 × ₹1,000/મહિનો = ₹6L/વર્ષ, માલ ખરીદ્યા વગર.
**Cost Saving:** —
**Automation:** self-signup, tenant બનાવવું, બિલિંગ.
**AI Opportunity:** AI નો ખર્ચ tenant પ્રતિ (#62) — નહીંતર નફો ખાઈ જાય.
**WhatsApp:** tenant પ્રતિ પોતાનો WhatsApp નંબર/token.
**Mobile/PWA:** એ જ એપ, tenant પ્રતિ બ્રાન્ડિંગ.
**DB Tables:** બધામાં `tenant_id`; `tenants`, `tenant_plans`, `tenant_usage` (નવાં).
**APIs:** બધા routes tenant-scoped; `POST ?r=tenant_signup`.
**Security:** **સૌથી કડક** — દરેક query માં tenant ગાળણ (એક ભૂલ = બીજી દુકાનનો ડેટા દેખાય); tenant પ્રતિ અલગ backup/encryption key; rate limit tenant પ્રતિ; tenant નો ડેટા બહાર લઈ જવાનો હક.
**Complexity:** Hard (**સૌથી મોટું કામ — પણ સૌથી મોટું ઇનામ**)
**Priority:** P3 (પહેલાં પોતાની દુકાનમાં 6 મહિના સ્થિર ચાલે પછી)
**Monetization:** **આ જ સૌથી મોટું monetization** — પેકેજ: Basic ₹499 / Pro ₹999 / AI ₹1,999 પ્રતિ મહિનો.
**Scalability:** tenant પ્રતિ DB કે shared + `tenant_id` index; queue tenant પ્રતિ.
**Example:** shop.akdwk.in (પોતાનું) + rajcomputer.akdwk.in + 48 વધુ → ₹6L વાર્ષિક પુનરાવર્તિત.

---
