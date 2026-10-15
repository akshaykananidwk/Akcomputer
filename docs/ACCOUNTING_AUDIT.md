# Accounting Module: તપાસ અને નિદાન (Audit report)

**સ્થિતિ:** ફક્ત તપાસ કરી છે. કોઈ entry, opening balance, journal કે ડેટામાં **કોઈ ફેરફાર કર્યો નથી.**
લાઇવ ડેટા અહીંથી દેખાતો નથી. એટલે તપાસ બે રીતે કરી:

1. Accounting ના બધા calculation નો કોડ લાઇન-બાય-લાઇન વાંચ્યો.
2. એક અલગ **test copy** માં નાની દુકાન બનાવી (GST બિલ, UPI, walk-in, return, ખર્ચ, opening balance) અને દરેક report ના આંકડા હાથથી ગણીને સરખાવ્યા.

તમારા ₹1,50,279.62 નું ચોક્કસ વિભાજન જોવા માટે એક **read-only** સ્ક્રીન ઉમેરી છે: **Accounting → 🩺 Accounting check**.
- તે ફક્ત વાંચે છે. કોઈ ફેરફાર કરતી નથી, અને એમાં કોઈ "Save" બટન નથી.
- તે ફરક ને કારણ પ્રમાણે ભાગમાં વહેંચે છે, અને બધા ભાગનો સરવાળો **બરાબર ફરક જેટલો જ** આવે છે.
- આ સરવાળો સાચો આવે છે તે ટેસ્ટથી સાબિત કર્યું છે: 16 અલગ પ્રકારની entry એક પછી એક ઉમેરી, અને દરેક વખતે સરવાળો પૈસા સુધી સાચો આવ્યો.

---

## મુખ્ય તારણ (ટૂંકમાં)

**Trial Balance નો ફરક કોઈ એક ખોટી entry થી નથી.** મુખ્ય કારણ એ છે કે સોફ્ટવેરનું Trial Balance સાચું double-entry નથી:

- દરેક લાઇન અલગ ટેબલમાંથી **જુદી જુદી રીતે ગણાય છે.**
  - Cash: payments માંથી
  - Stock: આજની શેલ્ફની કિંમત પરથી
  - Receivable: પાર્ટી બેલેન્સ પરથી
  - Sales: બિલ પરથી
- કોઈ entry ની બંને બાજુ (Dr અને Cr) એક સાથે નોંધાતી નથી. એટલે જ્યાં પણ એક બાજુ ગણાય અને બીજી બાજુ ન ગણાય, ત્યાં ફરક ઊભો થાય છે.

ફરક ત્રણ પ્રકારના કારણોમાંથી બનેલો છે:

| પ્રકાર | એટલે શું | ઉદાહરણ |
|---|---|---|
| 🧮 **સોફ્ટવેર calculation** | Report જે રીતે ગણે છે એમાં જ ખામી | ખર્ચ Trial Balance માં આવતો જ નથી; Sales Return બે વાર બાદ થાય છે |
| 📂 **Opening data** | સોફ્ટવેર પહેલાંનાં જૂનાં બેલેન્સની સામે Capital નથી | પાર્ટી/બેંક opening, જૂનો સ્ટોક, જૂની રોકડ |
| ✍️ **Entry ની રીત** | અમુક entries જે રીતે થઈ છે તેને લીધે | UPI bank account વગર; settlement discount; cash adjustment |

Test copy માં બનાવેલી દુકાનનો ફરક ₹98,790 હતો. તેનું વિભાજન:

| કારણ | અસર |
|---|---|
| સ્ટોક (આજની કિંમત) | +96,000 |
| COGS | +64,000 |
| ખરીદી બિલ | −75,520 |
| ખર્ચ | −26,100 |
| Sales Return બે વાર | +5,900 |
| Walk-in (−590 બિલ, +300 મળેલા) | −290 |
| **સોફ્ટવેરનો કુલ ભાગ** | **+63,990** |
| પાર્ટી opening | −5,000 |
| બેંક opening | +50,000 |
| **Opening નો કુલ ભાગ** | **+45,000** |
| UPI અને discount (bank વગર) | −20,200 |
| Opening cash adjustment | +10,000 |
| **Entry નો કુલ ભાગ** | **−10,200** |
| **કુલ** | **= ₹98,790, બરાબર** |

હાલનું Trial Balance આખા ફરકને "જૂની equity" કહે છે. **એ લખાણ ખોટું છે:** આ ઉદાહરણમાં opening નો ભાગ ફક્ત ₹45,000 છે.

---

## તમારા 10 મુદ્દા, એક પછી એક

### 1. Trial Balance: ₹1,50,279.62 નો ફરક
તમારો ચોક્કસ આંકડો જાણવા લાઇવ પર **🩺 Accounting check** ખોલવું પડશે. કોડમાંથી મળેલાં કારણો (દરેકની અસર check સ્ક્રીન રકમમાં બતાવશે):

| # | કારણ | પ્રકાર |
|---|---|---|
| 1 | **ખર્ચ Trial Balance માં નથી.** ખર્ચથી cash/bank ઘટે છે, પણ સામે કોઈ ખર્ચ ખાતું (Dr) TB માં નથી; ખર્ચ ફક્ત P&L માં દેખાય છે. | 🧮 |
| 2 | **ખરીદી:** સપ્લાયરને Cr થાય છે, પણ Purchases/Stock નો Dr નથી. Stock લાઇન = આજની શેલ્ફની કિંમત, entries નું પરિણામ નહીં. એટલે opening stock, stock સુધારા, ભાવ ફેરફાર અને ખરીદીનો GST/freight બધું ફરકમાં જાય છે. | 🧮 |
| 3 | **Sales Return બે વાર બાદ:** "Sales Revenue" લાઇન returns બાદ કરેલી છે, અને અલગ "Sales Returns" લાઇન ફરી બાદ કરે છે. | 🧮 |
| 4 | **Walk-in બિલનું બાકી:** વેચાણ તરીકે ગણાય છે, પણ ક્યાંય લેણામાં નથી. | 🧮 |
| 5 | **પાર્ટી અને બેંકનું opening balance:** સામે કોઈ Opening Capital નથી. | 📂 |
| 6 | **Settlement discount, contra, credit note, અને bank account વગરનાં UPI/card/cheque:** પાર્ટીનું બેલેન્સ બદલાય છે, પણ cash/bank/ખર્ચ ક્યાંય નથી. | ✍️ |
| 7 | **Cash/Bank adjustment:** સામે કોઈ ખાતું નથી (ઘણી વાર એ જૂની રોકડ હોય છે). | ✍️ |
| 8 | "Cash" mode ની payment પર bank account પણ હોય તો તે બે વાર ગણાય છે (જૂની entries માં હોઈ શકે). | ✍️ |
| 9 | Switch-off કરેલાં ખાતાં (account) TB માંથી ગાયબ થાય છે. અને જેના Dr ≠ Cr હોય તેવી journal entries. | ✍️ |

### 2. Bank Balance (Bank Ledger ≠ Trial Balance)
- **Bank Ledger** એક જ ખાતું બતાવે છે. **Trial Balance નું Bank** બધાં ખાતાંનો સરવાળો છે. આ સરવાળામાં આ પણ આવે છે:
  - Switch-off કરેલાં બેંક ખાતાં
  - ડિલીટ થયેલા bank account પર થયેલી entries
  - "1100 Bank" પર કરેલી journal entries (આ Cash & Bank પેજ પર દેખાતી નથી)
- Check સ્ક્રીન આ ચારેય રકમ અલગ અલગ બતાવે છે.
- ✅ **ખાતાવાર તપાસ:** Bank Ledger નું closing અને Cash & Bank પેજનું ખાતાનું બેલેન્સ **એક જ સૂત્ર** થી ગણાય છે (opening, receipts, payments, ખર્ચ, transfer, adjustment). કોડમાં આ બંને સરખાં છે.
- ⚠️ **મહત્વનું:** નવી શોપના default setup માં **UPI payment method નો type "other" છે, "bank" નહીં.** એટલે UPI થી આવેલા પૈસા **કોઈ bank account વગર** સેવ થાય છે, અને cash કે bank કોઈમાં ગણાતા નથી.
  - તમારા પોતાના setup માં UPI નો type શું છે, અને આ રીતે કેટલી રકમ બહાર રહી ગઈ છે, તે check સ્ક્રીન mode પ્રમાણે બતાવશે.
  - Bank passbook સાથે ફરક પડવાનું આ મોટું કારણ હોઈ શકે છે.

### 3. Opening Balances
- ✅ **પાર્ટીનું opening** લેજર, Receivable અને એપમાં બધે સરખું ગણાય છે.
- ✅ **બેંકનું opening** બેંક ખાતામાં ગણાય છે.
- ⚠️ **Stock:** સોફ્ટવેર પાસે "opening stock" ની કોઈ રકમ નથી, ફક્ત આજનો સ્ટોક છે. એટલે જૂનો સ્ટોક ફરકમાં જાય છે.
- ⚠️ **Cash:** opening cash ની કોઈ જગ્યા નથી. મોટાભાગે એ "cash adjustment" તરીકે નખાયેલું હોય છે.
- ⚠️ **Capital:** આ બધાં opening ની સામે કોઈ "Opening Capital" entry નથી. Balance Sheet તે ફરકને "old equity not yet recorded" કહે છે.
- **નિષ્કર્ષ:** આ **opening data નો ભાગ** છે, calculation ની ભૂલ નહીં. પણ હાલનું સોફ્ટવેર આ ભાગને બાકીનાં (સોફ્ટવેર) કારણોથી અલગ બતાવતું નથી. નવી check સ્ક્રીન અલગ બતાવે છે.

### 4. Profit & Loss
- ✅ **Sales:** રદ (cancelled) બિલ બાકાત રહે છે, અને returns **એક જ વાર** બાદ થાય છે. P&L માં આ સાચું છે.
- ✅ **COGS:** બિલ વખતે નોંધાયેલી ખરીદ કિંમતથી ગણાય છે. ભાવ પછી બદલાય તો પણ જૂનો નફો બદલાતો નથી. સર્વિસ આઇટમની cost ફક્ત તે બિલની બહારની cost છે.
- ⚠️ **GST આવકમાં ગણાય છે.** P&L ની આવક = બિલનો total, GST સાથે. એ GST સરકારનો છે, એટલે **નફો એટલો વધારે દેખાય છે.** Balance Sheet માં "GST payable" પણ નથી. (Test માં ₹14,490.)
- ⚠️ **Return થયેલો માલ શેલ્ફ પર પાછો આવે છે, પણ તેની cost COGS માંથી બાદ થતી નથી.** એટલે નફો ઓછો દેખાય છે.
- ⚠️ ખર્ચના અમુક પ્રકાર ખરેખર ખર્ચ નથી. તે મુદ્દો 8 માં વિગતે છે.
- Test copy માં P&L એ −₹1,010 (ખોટ) બતાવી. સાચી રીતે ગણતાં ≈ +₹2,900 નફો આવે (લોનના વ્યાજનો ભાગ હજી બાદ કરવાનો બાકી). એટલે ઉપરની ભૂલો એકબીજાને આંશિક રીતે ઢાંકી દે છે, અને આંકડો સાચો દેખાય તો પણ સાચો હોય એ જરૂરી નથી.

### 5. Stock અને Purchase
- ✅ **સ્ટોકની હેરફેર** હંમેશા એક જ function થી થાય છે: ખરીદી (+), વેચાણ (−), kit (પાર્ટ્સ), સર્વિસ (કોઈ અસર નહીં).
- ✅ **સ્ટોકની કિંમત** Balance Sheet, Trial Balance અને Stock report માં એક જ આંકડો છે.
- ⚠️ **સ્ટોકની કિંમત = આજનો જથ્થો × આજનો ખરીદ ભાવ, ભૂતકાળની તારીખ માટે પણ.** એટલે જૂની તારીખનું Balance Sheet આજના સ્ટોકથી બને છે.
- ⚠️ **ખરીદ બિલના total માં GST (input credit) અને freight પણ છે.** ખરીદી આ total થી ગણાય છે, પણ સ્ટોક ફક્ત ભાવથી.
- **Negative stock** અને **cost વગરનાં બિલ** check સ્ક્રીન ગણી બતાવે છે.

### 6. Party Ledger
- ✅ પાર્ટીનું બેલેન્સ **બધે એક જ સૂત્ર** થી ગણાય છે: opening + બિલ − sales return − ખરીદી + purchase return − મળેલા + આપેલા.
  - લેજર, Receivable, Balance Sheet અને એપમાં આ સરખું છે.
  - એપ 4.1 ના ટેસ્ટમાં 418 માંથી 418 પાર્ટી સરખી આવી.
- ✅ Payment-In/Out માં પહેલાં સૌથી જૂનાં બિલ ભરાય છે.
- ⚠️ **Walk-in બિલનું બાકી** કોઈ લેજરમાં નથી (test માં ₹290). Check સ્ક્રીન કુલ રકમ બતાવે છે.

### 7. Cash અને Bank ના વ્યવહાર
- ✅ **Cash in Hand:** Cash & Bank પેજ, દિવસ બંધ (day close) અને Trial Balance ત્રણેય **એક જ સૂત્ર** થી ગણાય છે.
- ✅ Cash→Bank અને Bank→Cash transfer સરવાળામાં નેટ શૂન્ય થાય છે. આ સાચું છે.
- ⚠️ Adjustment ની સામે કોઈ ખાતું નથી (જુઓ 1.7).
- ⚠️ **"1000 Cash" પર journal entry** કરો તો Trial Balance ની cash બદલાય છે, પણ Cash & Bank પેજની નહીં. એટલે બંને આંકડા જુદા પડે છે.

### 8. ખર્ચના પ્રકાર
અત્યારે નીચેના બધા પ્રકાર **P&L માં ખર્ચ તરીકે બાદ થાય છે**, એટલે નફો ઓછો દેખાય છે:

| પ્રકાર | સાચી accounting જગ્યા |
|---|---|
| **Staff Advance** | સ્ટાફ પાસેથી લેવાના પૈસા (asset) |
| **Loan EMI** | મોટો ભાગ લોન ચૂકવણી (liability ઘટે); ફક્ત **વ્યાજનો ભાગ** ખર્ચ |
| **ઘરખર્ચ / personal / owner** | **Drawings** (capital માંથી ઉપાડ) |
| Salary, ભાડું, ચા-પાણી વગેરે | ✅ ખર્ચ, સાચું |

- P&L માં ઘરખર્ચ અલગ box માં બતાવાય છે, પણ ગણતરીમાં તે ખર્ચ તરીકે જ ગણાય છે.
- Books → Loans માં EMI નું વ્યાજ અને મુદ્દલ અલગ ગણાય છે, પણ P&L એ ભાગલો વાપરતું નથી.

### 9. Journal Entries અને Adjustments
Check સ્ક્રીન આ બધું ગણી બતાવે છે:

| તપાસ | શું શોધે છે |
|---|---|
| ભૂલવાળી journal | Dr ≠ Cr હોય તેવી entries |
| Automatic ખાતાં પર journal | Cash/Bank/Stock/પાર્ટી ખાતાં પર સીધી journal entry, જે મૂળ સ્ક્રીન પર દેખાતી નથી |
| Duplicate payment | એક જ બિલ, રકમ, તારીખ અને mode |
| "paid" નો મેળ | બિલ પર "paid" લખેલી રકમ ≠ તેની સામે નોંધાયેલાં payments |

- ✅ **એપમાંથી ડુપ્લિકેટ ન થાય તેની ચોકી છે:** દરેક entry નો પોતાનો ID હોય છે, અને database એ ID ને unique રાખે છે.
- ✅ **બિલ સેવ થવામાં કંઈ skip થતું નથી:** બિલ, સ્ટોક અને payment એક જ transaction માં સેવ થાય છે. વચ્ચે ભૂલ આવે તો આખું પાછું ફરે છે.

### 10. Reports એકબીજા સાથે મેળ ખાય છે?

| સરખામણી | પરિણામ |
|---|---|
| General Ledger ↔ Trial Balance | ✅ દરેક ખાતા માટે એક જ function વપરાય છે |
| P&L ↔ Balance Sheet (Retained earnings) | ✅ એક જ net profit વપરાય છે |
| Stock report ↔ Balance Sheet | ✅ એક જ stock value |
| Bank Ledger ↔ Cash & Bank પેજ | ✅ ખાતાવાર સરખું |
| Bank Ledger ↔ Trial Balance | ⚠️ ફક્ત બધાં ખાતાંનો સરવાળો અને switch-off ખાતાં (જુઓ 2) |
| Trial Balance ↔ પોતે (Dr = Cr) | ❌ ઉપરનાં કારણોથી ક્યારેય સરખું નહીં થાય |

---

## શું સાચું છે (confirm)

- બિલ, ખરીદી, પેમેન્ટ અને ખર્ચ સેવ થવાની રીત. વેબસાઈટ અને એપ બંને એક જ function વાપરે છે.
- પાર્ટીના બેલેન્સનું સૂત્ર, અને Cash in Hand નું સૂત્ર.
- બેંક ખાતાનું ખાતાવાર બેલેન્સ.
- COGS ની પદ્ધતિ (બિલ વખતની cost).
- P&L માં return એક જ વાર બાદ થાય છે.
- Transfer નેટ શૂન્ય થાય છે.
- Ledger ↔ Trial Balance ↔ Balance Sheet ના આંકડા એક જ function થી આવે છે.

---

## ઉકેલ: તમારી મંજૂરી પછી જ

**ડેટામાં કંઈ નથી બદલ્યું.** સૂચવેલા ઉકેલ ત્રણ ભાગમાં છે. પહેલા બે ભાગ ફક્ત **calculation (કોડ)** બદલે છે, કોઈ entry નહીં.

**A. Trial Balance ને સાચું double-entry બનાવવું (ફક્ત કોડ)**
1. ખર્ચ ખાતાં (પ્રકાર પ્રમાણે) Trial Balance માં ઉમેરવાં.
2. ખરીદી → Purchases/Stock નો Dr; GST input તેની અલગ લાઇનમાં.
3. Sales Revenue ગ્રોસ બતાવવી (returns ફક્ત Sales Returns લાઇનમાં).
4. Walk-in નું બાકી → "Walk-in customers" લેણું.
5. Discount → "Discount allowed"; credit note → purchase return.
6. સ્ટોક: opening + ખરીદી − COGS. શેલ્ફ સાથેનો ફરક → "Stock adjustment".
7. Opening (પાર્ટી, બેંક, સ્ટોક, cash) → એક અલગ "Opening balances" લાઇન, જેથી બાકી ફરક સ્પષ્ટ દેખાય.

**B. P&L સુધારવું (ફક્ત કોડ)**
1. આવક GST વગર ગણવી, અને Balance Sheet માં "GST payable" લાઇન ઉમેરવી.
2. Return થયેલા માલની cost COGS માંથી બાદ કરવી.
3. ખર્ચના પ્રકારને ખાતું સોંપવું (Staff Advance = asset, EMI મુદ્દલ = loan, ઘરખર્ચ = Drawings). આ mapping Settings માંથી બદલી શકાય તેવું રાખવું.

**C. Setting અને entries (દરેક પગલું તમારી અલગ મંજૂરી પછી)**
1. UPI payment method નો type "bank" કરવો, જેથી **હવે પછીનાં** UPI બેંકમાં ગણાય.
2. જૂનાં bank account વગરનાં UPI/card entries ને bank account સોંપવું. આ ડેટા ફેરફાર છે, એટલે પહેલાં યાદી બતાવીશ.
3. Opening Capital ની એક entry. આ પણ ડેટા ફેરફાર છે.

---

## હવે તમારે શું કરવાનું

1. વેબસાઈટ પર **Settings → Update** દબાવો. Migrate ની જરૂર નથી, કારણ કે database બદલાતો નથી.
2. **Accounting → 🩺 Accounting check** ખોલો. "Up to" તારીખ એ જ રાખો જે તારીખે ₹1,50,279.62 દેખાયો હતો.
3. એ પેજનો screenshot મોકલો. તમારા ફરકનો દરેક રૂપિયો કયા કારણથી છે તે હું ચોક્કસ કહીશ, અને પછી A/B/C માંથી શું કરવું તે તમે નક્કી કરજો.

---
---

# ભાગ 2: Data Health Check ની 6 ચેતવણીઓ, entry by entry

**સ્થિતિ:** ફક્ત તપાસ કરી છે. કોઈ transaction, payment link, stock, opening, purchase કે journal માં **કોઈ ફેરફાર કર્યો નથી.**

દરેક શંકા અલગ **test copy** માં ખરેખર entry બનાવીને ચકાસી (rollback સાથે). લાઇવ ડેટાની દરેક entry જોવા માટે નવી read-only સ્ક્રીન ઉમેરી છે:
**Accounting → 🩺 Accounting check → "🔎 Data Health warnings, entry by entry"**

એ સ્ક્રીન દરેક અસરગ્રસ્ત entry ની યાદી બનાવે છે અને દરેક પર લેબલ લગાડે છે:

| લેબલ | અર્થ |
|---|---|
| `software check` | Entry સાચી છે; ચેતવણી ખોટી (check ની ભૂલ) |
| `entry` | ખરેખર entry તપાસવાની છે |
| `free / gift - keep` | જાણી જોઈને કરેલી free / 100% discount entry. **રાખવાની છે, delete નહીં** |

## પહેલું કામ: Backup ની ખાતરી (કોઈ પણ સુધારા પહેલાં)

લાઇવ સર્વર મને અહીંથી દેખાતું નથી. એટલે backup ની ખાતરી તમારે કરવી પડશે:

1. **Settings → Backup → "Download backup now"** દબાવો. ફાઇલ ખુલે છે કે નહીં તે જુઓ, અને તેને Drive/Pen-drive માં રાખો.
2. Entry-by-entry સ્ક્રીનના **વિભાગ 6** માં જુઓ: "Last automatic backup" ની તારીખ અને "Backup password set".
3. Automatic backup **cron** થી ચાલે છે. Cron ન ચાલે તો automatic backup બિલકુલ લેવાતો નથી.

**નિયમ:** સુધારાની મંજૂરી આપો ત્યારે પણ, પહેલાં એ દિવસનો નવો backup લઈશું, અને પછી જ સુધારો કરીશું.

---

## 1. Bills અને Payments mismatch (5 બિલ)

**શું છે:** Health Check બિલ પરનું "paid" ફક્ત પછીથી link થયેલાં payments સાથે સરખાવે છે. બિલ બનાવતી વખતે ભરાયેલા પૈસા (counter પર cash/UPI) એ ગણતરીમાં લેતું જ નથી.

**Test copy માં:** ₹500 નું બિલ બનાવ્યું. ₹300 બિલ વખતે ભરાયા અને ₹200 પછી Payment-In થી. બિલ સાચું અને પૂરું ભરાયેલું હતું, છતાં Health Check એ તેને **mismatch** બતાવ્યું (paid 500, linked 200).

**ભૂલ કોની?**
- મોટાભાગે **સોફ્ટવેરના check ની** (ખોટી ચેતવણી).
- નવી સ્ક્રીન દરેક બિલ માટે સાચી રીતે ગણે છે: બિલ વખતનાં payments + પછી link થયેલાં payments.
  - `software check` લેબલ = બિલ સાચું છે.
  - `entry` લેબલ = ખરેખર ફરક છે, અને કેટલા ₹ નો તે પણ બતાવે છે. કારણ: payment પછીથી delete/edit થયું, કે બિલ પર "paid" હાથે લખ્યું.

**સુધારો (મંજૂરી પછી):**
- Health Check ની ગણતરી સુધારવી. આ ફક્ત કોડ છે, ડેટા નહીં.
- `entry` વાળાં બિલ દરેક એક પછી એક તમને બતાવીને સુધારવાં.

**પછી ફરી શું ચકાસીશું:** Data Health Check, Party Ledger, Receivables, Bank/Cash Ledger.

## 2. ₹0 ની Purchase Bills (10+)

**સ્ક્રીન દરેક ₹0 બિલ માટે બતાવે છે:** બિલ નંબર, તારીખ, સપ્લાયર, દરેક લાઇન (આઇટમ × જથ્થો × ભાવ), subtotal − discount + GST = total, અને નીચેનામાંથી કયો પ્રકાર:

| પ્રકાર | ઓળખ | શું કરવું |
|---|---|---|
| **Free items, ₹0 ભાવ** | બધી લાઇનનો ભાવ ₹0 | ✅ રાખવાનું (gift) |
| **100% discount** | Discount ≥ subtotal | ✅ રાખવાનું (gift) |
| **અધૂરું** | કોઈ આઇટમ લાઇન જ નથી | તમે confirm કરો પછી જ |
| **શંકાસ્પદ** | લાઇનમાં ભાવ છે, છતાં total ₹0 | તમે confirm કરો પછી જ |

**આવી entries ની અસર (test copy માં ચકાસ્યું):**

| પરિણામ | ₹0 ભાવ વાળી entry | 100% discount વાળી entry |
|---|---|---|
| Stock જથ્થો | ✅ વધે છે, સાચું | ✅ વધે છે, સાચું |
| Supplier balance | ✅ કંઈ બદલાતું નથી | ⚠️ **GST ફર્મ પર** total ₹0 નહીં, GST જેટલું (₹108) રહે છે, અને સપ્લાયરને એટલું આપવાનું બાકી બને છે |
| Purchase register | ✅ ₹0 | ⚠️ GST ફર્મ પર GST જેટલું |
| **આઇટમનો ખરીદ ભાવ** | ❌ **₹0 થઈ જાય છે!** (પહેલાં ₹300 હતો) | ✅ મૂળ ભાવ રહે છે |
| Inventory valuation | ❌ એ આઇટમનો **આખો** સ્ટોક ₹0 ગણાય છે | ⚠️ free માલ પણ પૂરા ભાવે ગણાય છે |
| COGS / નફો | ❌ એ આઇટમના પછીનાં વેચાણમાં cost ₹0, એટલે **નફો વધારે** દેખાય. આ આગલી paid ખરીદી સુધી ચાલુ રહે | ⚠️ free માલની cost પૂરા ભાવે ગણાય, એટલે નફો થોડો ઓછો |

**ભૂલ કોની?**
- Entry **તમારી સાચી છે.**
- **સોફ્ટવેરની logic ખોટી છે:**
  - (a) ₹0 ભાવની લાઇન આઇટમનો સામાન્ય ખરીદ ભાવ ભૂંસી નાખે છે.
  - (b) GST, discount બાદ કર્યા **પહેલાં** ગણાય છે.
  - (c) Purchase માં લાઇન-પ્રમાણે discount નથી, ફક્ત આખા બિલ પર.

**Free items માટે સાચી accounting:**
- જથ્થો: સ્ટોકમાં ઉમેરવો.
- Cost: ₹0. એટલે weighted-average cost થોડી ઘટે, પણ ₹0 ન થઈ જાય.
- સપ્લાયર: કંઈ બાકી નહીં.
- GST: free માલ પર કોઈ GST નહીં.

**સુધારો (મંજૂરી પછી, કોડ):**
1. ₹0 (free) લાઇન આઇટમનો ખરીદ ભાવ **બદલે નહીં.** Cost layer માં ₹0 નો હિસ્સો જાય, જેથી average સાચી રહે.
2. Purchase માં લાઇન પર "Free" નો વિકલ્પ, અથવા લાઇન-પ્રમાણે discount.
3. GST, discount બાદ કર્યા **પછી** ગણવો.
   - **આ જ ખામી Sales બિલમાં પણ છે:** ₹500 પર ₹100 discount આપો તો GST ₹400 પર ₹72 થવો જોઈએ, પણ ₹90 ગણાય છે. **GST return ની દ્રષ્ટિએ આ મહત્વનું છે.**

**જૂની entries:**
- જે આઇટમનો ખરીદ ભાવ ₹0 થઈ ગયો છે, તેની યાદી સ્ક્રીન આપે છે ("its purchase price is now ₹0").
- એ આઇટમનો સાચો ભાવ પાછો મૂકવો એ ડેટા ફેરફાર છે, એટલે **તમારી મંજૂરી પછી**, આઇટમ પ્રમાણે.
- Gift entries **delete કે change નહીં થાય.**

**પછી ફરી શું ચકાસીશું:** Stock value, Product-wise profit, P&L (COGS), Purchase register, Supplier ledger, GSTR-3B (ITC).

## 3. Stock અને Serial mismatch, negative stock (9 items)

**Negative stock:**
- સ્ક્રીન દરેક આઇટમ માટે છેલ્લી ખરીદી અને છેલ્લા વેચાણની તારીખ બતાવે છે, અને કારણ: *"ખરીદી કદી નોંધાઈ નથી"* / *"છેલ્લી ખરીદી પછી વધારે વેચાયું"*.
- **ભૂલ કોની?** Entry ની. માલ પહેલાં વેચાયો અને ખરીદી પછી નોંધાઈ (કે નોંધાઈ જ નહીં).
- Setting "Allow billing more than the stock" ચાલુ છે, એટલે સોફ્ટવેર આવું થવા દે છે. **સોફ્ટવેરની ગણતરી સાચી છે.**

**Serial ≠ stock:**

| જે દેખાય | મોટાભાગે કારણ |
|---|---|
| Stock > serial | Opening stock કે stock adjustment serial વગર નાખ્યો, અથવા વેચાણ serial પસંદ કર્યા વગર થયું |
| Serial > stock | Stock ઘટ્યો (adjustment, handover) પણ serial "in stock" જ રહ્યા, અથવા stock negative છે |

**ભૂલ કોની?** Entry ની.

**સુધારો (મંજૂરી પછી):**
1. ખૂટતી purchase bills નાખવી.
2. 🔧 **Serial/Stock Repair** (`serial_fix.php`) થી દરેક આઇટમ તમને બતાવીને સરખી કરવી.
3. સોફ્ટવેરમાં serial-વાળી આઇટમના stock adjustment વખતે serial ફરજિયાત માગવો.

**પછી ફરી શું ચકાસીશું:** Stock report, Serial list, Stock value, Data Health Check.

## 4. Party Ledger mismatch (Zala bapu (jijaji) સહિત 3)

**શું છે:** Health Check કહે છે *"ખુલ્લાં બિલનો સરવાળો લેજર કરતાં વધારે છે, કોઈ payment link નથી"*. પણ test copy માં બીજું કારણ મળ્યું.

**મળેલું કારણ:** જે પાર્ટીને **વેચીએ અને જેની પાસેથી ખરીદીએ પણ**, તેનું લેજર બંનેને net કરે છે (વેચાણ − ખરીદી). પણ "ખુલ્લાં બિલ" માં ફક્ત વેચાણનાં બિલ ગણાય છે. **આ ભૂલ નથી**, લેજર સાચું છે. Health Check નું કારણ ખોટું લખાયું છે.

**સ્ક્રીન દરેક પાર્ટી માટે ફરક ભાગમાં બતાવે છે:**
- તેમની કેટલી purchase bills બાકી છે
- કેટલું payment કોઈ બિલ સાથે link નથી
- કેટલા sales return
- કેટલું opening advance

**ભૂલ કોની?**
- Purchase netting વાળો ભાગ = સાચું (software check નું કારણ ખોટું લખાયું છે).
- Link વગરનું payment = entry. એ payment ને બિલ સાથે link કરવું.

**સુધારો (મંજૂરી પછી):**
- Link કરવાનાં payments તમને બતાવીને link કરવાં.
- જે પાર્ટી સાથે વેચાણ અને ખરીદી બંને હોય તેમાં **Contra-settle**, જો તમે બંને હિસાબ સરભર કરવા માગતા હો.
- Health Check નું લખાણ સાચું કરવું (કોડ).

**પછી ફરી શું ચકાસીશું:** Party ledger, Receivables/Payables, Reminder ની રકમ, Balance Sheet.

## 5. Accounting reconciliation (Trial Balance, opening, UPI)

ભાગ 1 માં વિગત છે. **⚖️ Trial Balance & reports** ટેબ ફરક ને કારણ પ્રમાણે ભાગમાં બતાવે છે, અને બધા ભાગનો સરવાળો ફરક જેટલો જ આવે છે. એ ટેબનો screenshot મોકલશો એટલે ₹1,50,279.62 નું ચોક્કસ વિભાજન કહીશ.

## 6. Security અને Technical

| મુદ્દો | શું છે | ભૂલ કોની | સુધારો |
|---|---|---|---|
| Backup password | Password ન હોય તો backup ફાઇલ કોઈ પણ ખોલી શકે | Setting | **Settings → Backup** માં password નાખો. આ તમારે જ કરવાનું, કારણ કે password ફક્ત તમારી પાસે રહેવો જોઈએ |
| Automatic backup | Cron ન ચાલે તો backup જ નથી લેવાતો | Hosting setup | નીચે "Cron" જુઓ |
| API key plain-text | અમુક key એન્ક્રિપ્ટ થયા વગર database માં પડી છે. સ્ક્રીન ફક્ત key નાં **નામ** બતાવે છે, કિંમત નહીં | Cron ન ચાલવાથી (રોજનું housekeeping key ને encrypt કરે છે) | Settings માં એ key ને એક વાર "Save" કરો એટલે તરત encrypt થાય. અથવા cron ચાલુ થાય એટલે જાતે થઈ જશે |
| Cron | "Cron last ran" જૂનું કે "never" હોય તો hosting માં cron સેટ નથી કે બંધ છે. Reminders, backup, WhatsApp, housekeeping બધું એના પર ચાલે છે | Hosting setup | cPanel → Cron Jobs માં દર 5 મિનિટે `cron.php` ચલાવવું (Cron Manager માં લાઇન લખેલી છે). Fail થતા job નું નામ અને કારણ સ્ક્રીન બતાવે છે |
| 93 errors | સ્ક્રીન એક જ પ્રકારની ભૂલોને ભેગી કરીને બતાવે છે: કઈ ભૂલ કેટલી વાર અને છેલ્લે ક્યારે | ભૂલ પ્રમાણે | એ ટેબલનો screenshot મોકલો. દરેક ભૂલનું કારણ અને સુધારો અલગ કહીશ. મોટાભાગે થોડી જ ભૂલો વારંવાર આવતી હોય છે |

---

## હવે શું કરવાનું (ક્રમ પ્રમાણે)

1. **Settings → Backup → Download backup now**. ફાઇલ સાચવી રાખો.
2. **Settings → Update** (Migrate ની જરૂર નથી).
3. **Accounting → 🩺 Accounting check** ખોલો, અને બંને ટેબના screenshot મોકલો:
   - "⚖️ Trial Balance & reports"
   - "🔎 Data Health warnings, entry by entry"
4. એ પછી હું દરેક અસરગ્રસ્ત entry માટે કહીશ કે શું કરવું, અને સુધારાની યાદી નક્કી કરીશું:
   - **ફક્ત કોડ:** check સુધારવા, free item logic, GST discount પછી ગણવો.
   - **ડેટા:** entry પ્રમાણે, તમારી દરેક મંજૂરી પછી.
