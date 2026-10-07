<?php
// The screens in Gujarati or Hindi, for a staff member who picks it in My
// Account. Only the words on the screen change (menus, buttons, headings);
// names, numbers and bills stay exactly as typed, so nothing a customer or
// the books see changes. A phrase not listed here simply stays in English.
//
// [English => [Gujarati, Hindi]]
function i18n_phrases() {
    return [
        // menu
        'Dashboard' => ['ડેશબોર્ડ', 'डैशबोर्ड'], 'Parties' => ['પાર્ટી', 'पार्टी'], 'All Parties' => ['બધી પાર્ટી', 'सभी पार्टी'],
        'Party Payments' => ['પાર્ટી પેમેન્ટ', 'पार्टी भुगतान'], 'Collection Queue' => ['ઉઘરાણી યાદી', 'वसूली सूची'],
        'Calls (in & out)' => ['કૉલ (આવતા-જતા)', 'कॉल (आने-जाने वाले)'], 'Call Setup' => ['કૉલ સેટઅપ', 'कॉल सेटअप'],
        'What the Phone Says' => ['ફોન શું બોલે', 'फ़ोन क्या बोले'], 'Campaigns' => ['કેમ્પેન', 'कैंपेन'],
        'Items' => ['આઇટમ', 'आइटम'], 'All Items' => ['બધી આઇટમ', 'सभी आइटम'], 'Import Excel/CSV' => ['Excel માંથી લાવો', 'Excel से लाएँ'],
        'Stock Levels' => ['સ્ટોક', 'स्टॉक'], 'Barcode Label Printing' => ['બારકોડ લેબલ', 'बारकोड लेबल'],
        'Batch / Expiry Tracking' => ['બેચ / એક્સપાયરી', 'बैच / एक्सपायरी'], 'Sale' => ['વેચાણ', 'बिक्री'],
        'Sale Invoices' => ['વેચાણ બિલ', 'बिक्री बिल'], 'Estimate / Quotation' => ['અંદાજ / ભાવપત્રક', 'अनुमान / कोटेशन'],
        'Sale Return' => ['વેચાણ પરત', 'बिक्री वापसी'], 'Delivery Challan' => ['ડિલિવરી ચલણ', 'डिलीवरी चालान'],
        'Purchase' => ['ખરીદી', 'खरीद'], 'Purchase Bills' => ['ખરીદી બિલ', 'खरीद बिल'], 'Purchase Return' => ['ખરીદી પરત', 'खरीद वापसी'],
        'Scan Bill (OCR)' => ['બિલ સ્કેન', 'बिल स्कैन'], 'Expenses' => ['ખર્ચ', 'खर्च'], 'Expense' => ['ખર્ચ', 'खर्च'],
        'Cash & Bank' => ['રોકડ અને બેંક', 'नकद और बैंक'], 'Cash & Bank Overview' => ['રોકડ અને બેંક', 'नकद और बैंक'],
        'Day closing' => ['દિવસ બંધ', 'दिन बंद'], 'Cheque register' => ['ચેક રજિસ્ટર', 'चेक रजिस्टर'],
        'Today collections (mobile)' => ['આજની ઉઘરાણી', 'आज की वसूली'], 'Bank Accounts' => ['બેંક ખાતા', 'बैंक खाते'],
        'Payment Methods' => ['પેમેન્ટ રીત', 'भुगतान तरीके'], 'Accounting' => ['હિસાબ', 'हिसाब'],
        'Chart of Accounts' => ['ખાતાની યાદી', 'खातों की सूची'], 'Journal Entries' => ['જર્નલ', 'जर्नल'],
        'General Ledger' => ['ખાતાવહી', 'खाता बही'], 'Trial Balance' => ['કાચું સરવૈયું', 'ट्रायल बैलेंस'],
        'Balance Sheet' => ['પાકું સરવૈયું', 'बैलेंस शीट'], 'Profit & Loss' => ['નફો-નુકસાન', 'लाभ-हानि'],
        'Stock / Godown' => ['સ્ટોક / ગોડાઉન', 'स्टॉक / गोदाम'], 'Handover / Transfer' => ['સોંપણી', 'सौंपना'],
        'My Stock' => ['મારો સ્ટોક', 'मेरा स्टॉक'], 'Repair & Service' => ['રિપેરિંગ અને સર્વિસ', 'रिपेयर और सर्विस'],
        'Repair Jobs' => ['રિપેરિંગ', 'रिपेयर'], 'Repairs' => ['રિપેરિંગ', 'रिपेयर'], 'Leads & CRM' => ['ગ્રાહક પૂછપરછ', 'ग्राहक पूछताछ'],
        'Leads' => ['પૂછપરછ', 'पूछताछ'], 'Follow-ups' => ['ફોલો-અપ', 'फ़ॉलो-अप'], 'Reminders' => ['યાદી', 'रिमाइंडर'],
        'Reports' => ['રિપોર્ટ', 'रिपोर्ट'], 'All Reports' => ['બધા રિપોર્ટ', 'सभी रिपोर्ट'], 'My Online Store' => ['મારી ઓનલાઇન દુકાન', 'मेरी ऑनलाइन दुकान'],
        'WhatsApp Inbox' => ['WhatsApp મેસેજ', 'WhatsApp संदेश'], 'Staff & Company' => ['સ્ટાફ અને કંપની', 'स्टाफ और कंपनी'],
        'Staff Users' => ['સ્ટાફ', 'स्टाफ'], 'Locations' => ['શાખા', 'शाखा'], 'Companies / Firms' => ['ફર્મ', 'फ़र्म'],
        'Settings' => ['સેટિંગ', 'सेटिंग'], 'My Account' => ['મારું ખાતું', 'मेरा खाता'], 'Logout' => ['બહાર નીકળો', 'लॉग आउट'],
        'Dark Mode' => ['ડાર્ક મોડ', 'डार्क मोड'], 'Light Mode' => ['લાઇટ મોડ', 'लाइट मोड'], 'Tables & kitchen' => ['ટેબલ અને રસોડું', 'टेबल और किचन'],
        'Appointments' => ['એપોઇન્ટમેન્ટ', 'अपॉइंटमेंट'], 'My plan' => ['મારો પ્લાન', 'मेरा प्लान'], 'Help' => ['મદદ', 'मदद'],
        // quick actions and bottom bar
        'Quick Actions' => ['ઝડપી કામ', 'जल्दी काम'], 'New Bill' => ['નવું બિલ', 'नया बिल'], 'New bill' => ['નવું બિલ', 'नया बिल'],
        'Estimate' => ['અંદાજ', 'अनुमान'], 'Payment In' => ['પૈસા આવ્યા', 'पैसे आए'], 'Payment Out' => ['પૈસા આપ્યા', 'पैसे दिए'],
        'Repair Job' => ['રિપેરિંગ', 'रिपेयर'], 'Task' => ['કામ', 'काम'], 'Handover' => ['સોંપણી', 'सौंपना'], 'Challan' => ['ચલણ', 'चालान'],
        'Party' => ['પાર્ટી', 'पार्टी'], 'Item' => ['આઇટમ', 'आइटम'], 'Lead' => ['પૂછપરછ', 'पूछताछ'], 'Ticket' => ['ફરિયાદ', 'शिकायत'],
        'Follow-up' => ['ફોલો-અપ', 'फ़ॉलो-अप'], 'To Receive' => ['લેવાના', 'लेने हैं'], 'To Pay' => ['આપવાના', 'देने हैं'],
        'Sale list' => ['બિલ યાદી', 'बिल सूची'], 'Stock Items' => ['સ્ટોક', 'स्टॉक'], 'Search menu...' => ['મેનુ શોધો...', 'मेनू खोजें...'],
        'Search parties, items, bills...' => ['પાર્ટી, આઇટમ, બિલ શોધો...', 'पार्टी, आइटम, बिल खोजें...'],
        // bill form
        'Write a sale for a walk-in or a regular customer.' => ['ચાલતા કે નિયમિત ગ્રાહક માટે વેચાણ લખો.', 'राह चलते या नियमित ग्राहक की बिक्री लिखें.'],
        'Credit' => ['ઉધાર', 'उधार'], 'Invoice No.' => ['બિલ નં.', 'बिल नं.'], 'Time' => ['સમય', 'समय'], 'Firm Name' => ['ફર્મનું નામ', 'फ़र्म का नाम'],
        'Godown / Location' => ['ગોડાઉન / શાખા', 'गोदाम / शाखा'], 'Payment Terms' => ['ચુકવણીની શરત', 'भुगतान की शर्त'], 'Due On' => ['ક્યારે બાકી', 'कब बाकी'],
        'Price Type' => ['ભાવ પ્રકાર', 'भाव प्रकार'], 'New party' => ['નવી પાર્ટી', 'नई पार्टी'], 'Phone Number' => ['ફોન નંબર', 'फ़ोन नंबर'],
        'Quick add a new party' => ['ઝડપથી નવી પાર્ટી ઉમેરો', 'जल्दी नई पार्टी जोड़ें'], 'Add & select' => ['ઉમેરો અને પસંદ કરો', 'जोड़ें और चुनें'],
        'What is being sold on this bill' => ['આ બિલમાં શું વેચાય છે', 'इस बिल में क्या बिक रहा है'], 'Add Items' => ['આઇટમ ઉમેરો', 'आइटम जोड़ें'],
        '(optional)' => ['(જરૂરી નથી)', '(ज़रूरी नहीं)'], 'Scan' => ['સ્કેન', 'स्कैन'], 'No items added yet.' => ['હજી કોઈ આઇટમ નથી.', 'अभी कोई आइटम नहीं.'],
        'Add Items to Sale' => ['બિલમાં આઇટમ ઉમેરો', 'बिल में आइटम जोड़ें'], 'Totals & Taxes' => ['કુલ અને ટેક્સ', 'कुल और टैक्स'],
        'Subtotal (Rate x Qty)' => ['પેટા કુલ (ભાવ × નંગ)', 'उप योग (भाव × मात्रा)'], 'Adjustment (₹, +/-)' => ['સુધારો (₹, +/-)', 'समायोजन (₹, +/-)'],
        'A second way to pay too (cash + UPI)' => ['બીજી રીતે પણ ચુકવણી (રોકડ + UPI)', 'दूसरे तरीके से भी भुगतान (नकद + UPI)'],
        'Trade-in (exchange)' => ['જૂનું બદલામાં', 'पुराना बदले में'], 'Add another' => ['બીજું ઉમેરો', 'और जोड़ें'], 'Delivery address' => ['ડિલિવરી સરનામું', 'डिलीवरी पता'],
        'Bill Summary' => ['બિલ સારાંશ', 'बिल सारांश'], 'Item Discounts' => ['આઇટમ વળતર', 'आइटम छूट'], 'Adjustment' => ['સુધારો', 'समायोजन'],
        'Round Off' => ['રાઉન્ડ ઓફ', 'राउंड ऑफ़'], 'Round Off Total' => ['રાઉન્ડ કુલ', 'राउंड कुल'], 'Type a customer name or mobile…' => ['ગ્રાહકનું નામ કે મોબાઇલ લખો…', 'ग्राहक का नाम या मोबाइल लिखें…'],
        'Customer name (blank = walk-in)' => ['ગ્રાહકનું નામ (ખાલી = ચાલતો ગ્રાહક)', 'ग्राहक का नाम (खाली = राह चलता)'], 'WhatsApp number (optional)' => ['WhatsApp નંબર (જરૂરી નથી)', 'WhatsApp नंबर (ज़रूरी नहीं)'],
        'Party name *' => ['પાર્ટીનું નામ *', 'पार्टी का नाम *'], 'GSTIN (optional)' => ['GSTIN (જરૂરી નથી)', 'GSTIN (ज़रूरी नहीं)'],
        'Anything worth writing on the bill' => ['બિલ પર લખવા જેવું કંઈ', 'बिल पर लिखने लायक कुछ'], 'Customer / Party *' => ['ગ્રાહક / પાર્ટી *', 'ग्राहक / पार्टी *'],
        'Received amount (₹) *' => ['મળેલ રકમ (₹) *', 'मिली रकम (₹) *'], 'Discount (Rs)' => ['વળતર (₹)', 'छूट (₹)'], 'Cheque number' => ['ચેક નંબર', 'चेक नंबर'],
        'Which bank' => ['કઈ બેંક', 'कौन सा बैंक'], 'Cheque date' => ['ચેક તારીખ', 'चेक तारीख'], 'Send WhatsApp receipt to party' => ['પાર્ટીને WhatsApp રસીદ મોકલો', 'पार्टी को WhatsApp रसीद भेजें'],
        'Link to a Bill (optional)' => ['બિલ સાથે જોડો (જરૂરી નથી)', 'बिल से जोड़ें (ज़रूरी नहीं)'], 'Select a party first.' => ['પહેલા પાર્ટી પસંદ કરો.', 'पहले पार्टी चुनें.'],
        // dashboard
        'Collections' => ['ઉઘરાણી', 'वसूली'], 'Every figure' => ['બધા આંકડા', 'सारे आँकड़े'], 'Collection priority' => ['ઉઘરાણી ક્રમ', 'वसूली क्रम'],
        'who to ask first' => ['પહેલા કોને પૂછવું', 'पहले किससे पूछें'], 'Urgent' => ['તાકીદનું', 'ज़रूरी'], 'Open the collection list' => ['ઉઘરાણી યાદી ખોલો', 'वसूली सूची खोलें'],
        'What to buy' => ['શું ખરીદવું', 'क्या खरीदें'], 'Open the buying list' => ['ખરીદી યાદી ખોલો', 'खरीद सूची खोलें'], 'Estimated cost' => ['અંદાજિત ખર્ચ', 'अनुमानित लागत'],
        'Last payment' => ['છેલ્લી ચુકવણી', 'आख़िरी भुगतान'], 'Full list' => ['આખી યાદી', 'पूरी सूची'], 'Stock position' => ['સ્ટોકની સ્થિતિ', 'स्टॉक की स्थिति'],
        'Dead stock' => ['પડી રહેલો માલ', 'पड़ा हुआ माल'], 'Needs attention now' => ['હમણાં ધ્યાન આપો', 'अभी ध्यान दें'], 'Last sold' => ['છેલ્લે વેચાયું', 'आख़िरी बार बिका'],
        'See suggestions' => ['સૂચન જુઓ', 'सुझाव देखें'], 'Sales analysis' => ['વેચાણ વિશ્લેષણ', 'बिक्री विश्लेषण'], 'Top product' => ['સૌથી વધુ વેચાયેલ', 'सबसे ज़्यादा बिका'],
        'See all' => ['બધું જુઓ', 'सब देखें'], 'Top customer' => ['મુખ્ય ગ્રાહક', 'मुख्य ग्राहक'], 'Sale Overview' => ['વેચાણ ઝાંખી', 'बिक्री सारांश'],
        'Profit Trend' => ['નફાનો ગ્રાફ', 'मुनाफ़े का ग्राफ़'], 'Inventory Summary' => ['સ્ટોક સારાંશ', 'स्टॉक सारांश'], 'Low Stock Items' => ['ઓછો સ્ટોક', 'कम स्टॉक'],
        'Open Repair Jobs' => ['ચાલુ રિપેરિંગ', 'चालू रिपेयर'], 'Purchase list' => ['ખરીદી યાદી', 'खरीद सूची'], 'Stock items' => ['સ્ટોક', 'स्टॉक'],
        // common words and buttons
        'Save' => ['સેવ કરો', 'सेव करें'], 'Cancel' => ['રદ કરો', 'रद्द करें'], 'Edit' => ['બદલો', 'बदलें'], 'Delete' => ['કાઢી નાખો', 'हटाएँ'],
        'View' => ['જુઓ', 'देखें'], 'Add' => ['ઉમેરો', 'जोड़ें'], 'Search' => ['શોધો', 'खोजें'], 'Filter' => ['ફિલ્ટર', 'फ़िल्टर'],
        'Apply' => ['લાગુ કરો', 'लागू करें'], 'Back' => ['પાછા', 'वापस'], 'Next' => ['આગળ', 'आगे'], 'Close' => ['બંધ કરો', 'बंद करें'],
        'Print' => ['પ્રિન્ટ', 'प्रिंट'], 'Share' => ['શેર', 'शेयर'], 'Download' => ['ડાઉનલોડ', 'डाउनलोड'], 'Show' => ['બતાવો', 'दिखाएँ'],
        'Save & New' => ['સેવ અને નવું', 'सेव और नया'], 'Update Bill' => ['બિલ સુધારો', 'बिल अपडेट करें'], 'Park it' => ['બાજુ પર રાખો', 'रोक कर रखें'],
        'Date' => ['તારીખ', 'तारीख'], 'Total' => ['કુલ', 'कुल'], 'Amount' => ['રકમ', 'रकम'], 'Amount ₹' => ['રકમ ₹', 'रकम ₹'],
        'Paid' => ['ચૂકવેલ', 'चुकाया'], 'Due' => ['બાકી', 'बाकी'], 'Balance Due' => ['બાકી રકમ', 'बाकी रकम'], 'Balance due' => ['બાકી રકમ', 'बाकी रकम'],
        'Overdue' => ['મુદત વીતી', 'समय निकल गया'], 'Partial' => ['થોડા ચૂકવ્યા', 'आंशिक'], 'Cancelled' => ['રદ', 'रद्द'],
        'Customer' => ['ગ્રાહક', 'ग्राहक'], 'Supplier' => ['સપ્લાયર', 'सप्लायर'], 'Mobile' => ['મોબાઇલ', 'मोबाइल'], 'Notes' => ['નોંધ', 'नोट'],
        'Note' => ['નોંધ', 'नोट'], 'Category' => ['કેટેગરી', 'कैटेगरी'], 'Status' => ['સ્થિતિ', 'स्थिति'], 'Mode' => ['રીત', 'तरीका'],
        'Cash' => ['રોકડ', 'नकद'], 'Bank' => ['બેંક', 'बैंक'], 'Cheque' => ['ચેક', 'चेक'], 'Card' => ['કાર્ડ', 'कार्ड'],
        'Bank Transfer' => ['બેંક ટ્રાન્સફર', 'बैंक ट्रांसफ़र'], 'Bank account' => ['બેંક ખાતું', 'बैंक खाता'], 'Bank Account' => ['બેંક ખાતું', 'बैंक खाता'],
        'Today' => ['આજે', 'आज'], 'Yesterday' => ['ગઈકાલે', 'कल'], 'This week' => ['આ અઠવાડિયું', 'इस हफ़्ते'], 'This month' => ['આ મહિનો', 'इस महीने'],
        'Last month' => ['ગયો મહિનો', 'पिछला महीना'], 'Other dates' => ['બીજી તારીખ', 'दूसरी तारीख'], 'From' => ['થી', 'से'], 'To' => ['સુધી', 'तक'],
        'Stock' => ['સ્ટોક', 'स्टॉक'], 'Bill' => ['બિલ', 'बिल'], 'Bills' => ['બિલ', 'बिल'], 'Sales' => ['વેચાણ', 'बिक्री'], 'Purchases' => ['ખરીદી', 'खरीद'],
        'Profit' => ['નફો', 'मुनाफ़ा'], 'Discount' => ['વળતર', 'छूट'], 'Subtotal' => ['પેટા કુલ', 'उप योग'], 'Shipping' => ['ભાડું', 'भाड़ा'],
        'Retail' => ['છૂટક', 'खुदरा'], 'Credit / Udhar' => ['ઉધાર', 'उधार'], 'Cash / No credit' => ['રોકડ / ઉધાર નહીં', 'नकद / उधार नहीं'],
        'Payment mode' => ['પેમેન્ટ રીત', 'भुगतान तरीका'], 'Paid now (₹)' => ['હમણાં ચૂકવ્યા (₹)', 'अभी चुकाए (₹)'], 'Party name' => ['પાર્ટીનું નામ', 'पार्टी का नाम'],
        'Add item' => ['આઇટમ ઉમેરો', 'आइटम जोड़ें'], 'New Party' => ['નવી પાર્ટી', 'नई पार्टी'], 'New Item' => ['નવી આઇટમ', 'नया आइटम'],
        'New Purchase' => ['નવી ખરીદી', 'नई खरीद'], 'New purchase' => ['નવી ખરીદી', 'नई खरीद'], 'Add expense' => ['ખર્ચ ઉમેરો', 'खर्च जोड़ें'],
        'Received' => ['મળ્યા', 'मिले'], 'Receive' => ['લો', 'लें'], "You'll Get" => ['તમને મળશે', 'आपको मिलेगा'], "You'll Give" => ['તમારે આપવાના', 'आपको देना है'],
        'Due date' => ['છેલ્લી તારીખ', 'आख़िरी तारीख'], 'Credit term' => ['ઉધારની મુદત', 'उधार की अवधि'], 'Staff' => ['સ્ટાફ', 'स्टाफ'],
        'All' => ['બધા', 'सभी'], 'New' => ['નવું', 'नया'], 'Product' => ['વસ્તુ', 'सामान'], 'Service' => ['સર્વિસ', 'सर्विस'],
        'Days' => ['દિવસ', 'दिन'], 'days' => ['દિવસ', 'दिन'], 'Firm' => ['ફર્મ', 'फ़र्म'], 'Order' => ['ઓર્ડર', 'ऑर्डर'], 'Orders' => ['ઓર્ડર', 'ऑर्डर'],
        'Collected' => ['ઉઘરાવ્યા', 'वसूले'], 'Receivable' => ['લેવાના', 'लेने हैं'], 'Payable' => ['આપવાના', 'देने हैं'],
        'Net cash' => ['ચોખ્ખી રોકડ', 'शुद्ध नकद'], 'Average bill' => ['સરેરાશ બિલ', 'औसत बिल'], 'New customers' => ['નવા ગ્રાહક', 'नए ग्राहक'],
        'Write a sale' => ['વેચાણ લખો', 'बिक्री लिखें'], 'Goods coming in' => ['માલ આવ્યો', 'माल आया'], 'Take payment' => ['પૈસા લો', 'पैसे लें'],
        'Money received' => ['પૈસા મળ્યા', 'पैसे मिले'], 'Who to ask today' => ['આજે કોની પાસે માંગવા', 'आज किससे माँगें'], 'Pay out' => ['પૈસા આપો', 'पैसे दें'],
        'Money going out' => ['પૈસા ગયા', 'पैसे गए'], 'Shop spending' => ['દુકાન ખર્ચ', 'दुकान खर्च'], 'Jobs in hand' => ['હાથ પરનાં કામ', 'हाथ के काम'],
        'What to do today' => ['આજે શું કરવું', 'आज क्या करना है'], 'Chase payment' => ['ઉઘરાણી કરો', 'वसूली करें'], 'See the list' => ['યાદી જુઓ', 'सूची देखें'],
        'Out of stock' => ['સ્ટોક ખતમ', 'स्टॉक ख़त्म'], 'Stock value' => ['સ્ટોકની કિંમત', 'स्टॉक की कीमत'], 'Stock Value' => ['સ્ટોકની કિંમત', 'स्टॉक की कीमत'],
        'Buy now' => ['હમણાં ખરીદો', 'अभी खरीदें'], 'Selling fast' => ['ઝડપથી વેચાય છે', 'तेज़ी से बिक रहा'], 'Selling slowly' => ['ધીમું વેચાય છે', 'धीरे बिक रहा'],
        'Total outstanding' => ['કુલ બાકી', 'कुल बाकी'], 'Due today' => ['આજે બાકી', 'आज बाकी'], 'Send a reminder' => ['યાદ કરાવો', 'याद दिलाएँ'],
        'Customize' => ['ગોઠવો', 'सजाएँ'], 'Login History' => ['લૉગિન ઇતિહાસ', 'लॉगिन इतिहास'], 'Change Password' => ['પાસવર્ડ બદલો', 'पासवर्ड बदलें'],
        'Overview' => ['ઝાંખી', 'सारांश'], 'Display & language' => ['દેખાવ અને ભાષા', 'दिखावट और भाषा'], 'Language' => ['ભાષા', 'भाषा'],
        'Text size' => ['અક્ષરનું કદ', 'अक्षर का आकार'], 'Simple menu' => ['સરળ મેનુ', 'आसान मेनू'], 'Colour-blind safe colours' => ['રંગ-અંધ માટે સલામત રંગ', 'रंग-अंधता के लिए सुरक्षित रंग'],
        'Price' => ['ભાવ', 'भाव'], 'Qty' => ['નંગ', 'मात्रा'], 'Quantity' => ['જથ્થો', 'मात्रा'], 'Unit' => ['એકમ', 'इकाई'], 'Name' => ['નામ', 'नाम'],
        'Address' => ['સરનામું', 'पता'], 'City' => ['શહેર', 'शहर'], 'Description' => ['વર્ણન', 'विवरण'], 'Type' => ['પ્રકાર', 'प्रकार'],
        'Selling Price (Retail)' => ['વેચાણ ભાવ (છૂટક)', 'बिक्री भाव (खुदरा)'], 'Purchase Price' => ['ખરીદ ભાવ', 'खरीद भाव'], 'Save Item' => ['આઇટમ સેવ કરો', 'आइटम सेव करें'],
        'Item name' => ['આઇટમનું નામ', 'आइटम का नाम'], 'Opening balance' => ['શરૂઆતની બાકી', 'शुरुआती बाकी'], 'Ledger' => ['ખાતું', 'खाता'],
        'Paid by' => ['કોણે ચૂકવ્યા', 'किसने चुकाए'], 'Billed by' => ['બિલ બનાવનાર', 'बिल बनाने वाले'], 'Tables' => ['ટેબલ', 'टेबल'],
        'Send to kitchen' => ['રસોડે મોકલો', 'किचन भेजें'], 'Make my first bill' => ['મારું પહેલું બિલ બનાવો', 'मेरा पहला बिल बनाएँ'],
        'Nothing here yet.' => ['હજી અહીં કંઈ નથી.', 'अभी यहाँ कुछ नहीं है.'], 'Read aloud' => ['બોલીને સંભળાવો', 'बोलकर सुनाएँ'],
    ];
}

/** The dictionary for one language ('gu' or 'hi'), English => that language. */
function i18n_dict($lang) {
    $i = ['gu' => 0, 'hi' => 1][$lang] ?? null;
    if ($i === null) return [];
    $out = [];
    foreach (i18n_phrases() as $en => $tr) $out[$en] = $tr[$i];
    return $out;
}

/** The current user's display choices: lang (en/gu/hi), text (normal/large/xl), cbsafe, simple. */
function ui_prefs() {
    static $p = null;
    if ($p !== null) return $p;
    $u = function_exists('current_user') ? current_user() : null;
    $get = fn($k, $d) => $u ? (string)user_pref($u['id'], $k, $d) : $d;
    return $p = ['lang' => $get('ui_lang', 'en'), 'text' => $get('ui_text', 'normal'), 'cbsafe' => $get('ui_cbsafe', '0') === '1', 'simple' => $get('ui_simple', '0') === '1'];
}

/** A fixed word printed on the bill, in the bill's language (Settings → Invoice). Names and amounts are never translated. */
function bl($en) {
    static $words = [
        'Invoice No.' => ['બિલ નં.', 'बिल नं.'], 'Date' => ['તારીખ', 'तारीख'], 'Time' => ['સમય', 'समय'], 'Due' => ['છેલ્લી તારીખ', 'अंतिम तारीख'],
        'Item Description' => ['વસ્તુ', 'सामान'], 'Qty' => ['નંગ', 'मात्रा'], 'Rate' => ['ભાવ', 'भाव'], 'Amount' => ['રકમ', 'रकम'],
        'Subtotal' => ['પેટા કુલ', 'उप योग'], 'Shipping' => ['ભાડું', 'भाड़ा'], 'Adjustment' => ['સુધારો', 'समायोजन'], 'Round Off' => ['રાઉન્ડ ઓફ', 'राउंड ऑफ़'],
        'TOTAL' => ['કુલ', 'कुल'], 'Paid' => ['ચૂકવ્યા', 'चुकाए'], 'BALANCE DUE' => ['બાકી', 'बाकी'], 'PAID IN FULL' => ['પૂરા ચૂકવાઈ ગયા', 'पूरा भुगतान हो गया'],
        'Authorised Signatory' => ['અધિકૃત સહી', 'अधिकृत हस्ताक्षर'], 'Bill To' => ['ગ્રાહક', 'ग्राहक'],
    ];
    $i = ['gu' => 0, 'hi' => 1][setting('bill_lang', 'en')] ?? null;
    return $i === null || !isset($words[$en]) ? $en : $words[$en][$i];
}

/** A sentence for the 🔊 button, in the user's language. Amounts are whole rupees, so the phone reads them as numbers. */
function say_text($key, ...$args) {
    $t = [
        'bill' => ['Bill %s. Total %s rupees. Paid %s. Balance %s rupees.', 'બિલ %s. કુલ %s રૂપિયા. ચૂકવ્યા %s. બાકી %s રૂપિયા.', 'बिल %s. कुल %s रुपये. चुकाए %s. बाकी %s रुपये.'],
        'dash' => ["Today's sale %s rupees in %s bills. To receive %s rupees. To pay %s rupees.", 'આજનું વેચાણ %s રૂપિયા, %s બિલ. લેવાના %s રૂપિયા. આપવાના %s રૂપિયા.', 'आज की बिक्री %s रुपये, %s बिल. लेने हैं %s रुपये. देने हैं %s रुपये.'],
    ][$key];
    $i = ['gu' => 1, 'hi' => 2][ui_prefs()['lang']] ?? 0;
    return vsprintf($t[$i], array_map(fn($a) => is_numeric($a) ? (string)round((float)$a) : (string)$a, $args));
}
