-- v97: a fuller expense keyword list (English, Gujarati, Hindi) so the
-- category is picked right while staff type the note. Keywords only - no
-- expense entry is changed here (old entries are corrected from
-- Expenses -> Fix categories, by the owner, with Undo).
INSERT INTO expense_category_keywords (keyword, category) VALUES
('petrol', 'Petrol & Vehicle'), ('diesel', 'Petrol & Vehicle'), ('fuel', 'Petrol & Vehicle'), ('cng', 'Petrol & Vehicle'),
('પેટ્રોલ', 'Petrol & Vehicle'), ('डीजल', 'Petrol & Vehicle'), ('पेट्रोल', 'Petrol & Vehicle'), ('parking', 'Petrol & Vehicle'),
('puncture', 'Vehicle Repair'), ('tyre', 'Vehicle Repair'), ('bike service', 'Vehicle Repair'), ('car service', 'Vehicle Repair'), ('પંચર', 'Vehicle Repair'),
('courier', 'Courier & Transport'), ('transport', 'Courier & Transport'), ('delivery', 'Courier & Transport'), ('rickshaw', 'Courier & Transport'),
('auto fare', 'Courier & Transport'), ('taxi', 'Courier & Transport'), ('bus', 'Courier & Transport'), ('ભાડું રિક્ષા', 'Courier & Transport'), ('કુરિયર', 'Courier & Transport'),
('travel', 'Business Travel'), ('hotel', 'Business Travel'), ('train', 'Business Travel'), ('ticket', 'Business Travel'),
('tea', 'Tea & Water'), ('chai', 'Tea & Water'), ('coffee', 'Tea & Water'), ('water', 'Tea & Water'), ('water jug', 'Tea & Water'), ('ચા', 'Tea & Water'), ('પાણી', 'Tea & Water'), ('चाय', 'Tea & Water'),
('snacks', 'Food & Meals'), ('lunch', 'Food & Meals'), ('dinner', 'Food & Meals'), ('nasta', 'Food & Meals'), ('food', 'Food & Meals'), ('નાસ્તો', 'Food & Meals'), ('જમવાનું', 'Food & Meals'),
('rent', 'Office Rent'), ('shop rent', 'Office Rent'), ('દુકાન ભાડું', 'Office Rent'),
('salary', 'Salary & Wages'), ('wage', 'Salary & Wages'), ('staff pay', 'Salary & Wages'), ('પગાર', 'Salary & Wages'), ('वेतन', 'Salary & Wages'),
('advance', 'Staff Advance'), ('ઉપાડ', 'Staff Advance'),
('electricity', 'Electricity'), ('power bill', 'Electricity'), ('light bill', 'Electricity'), ('bijli', 'Electricity'), ('pgvcl', 'Electricity'), ('લાઈટ બિલ', 'Electricity'),
('internet', 'Internet & Telecom'), ('wifi', 'Internet & Telecom'), ('broadband', 'Internet & Telecom'), ('recharge', 'Internet & Telecom'), ('jio', 'Internet & Telecom'), ('airtel', 'Internet & Telecom'),
('domain', 'Hosting & Domain'), ('hosting', 'Hosting & Domain'), ('hostinger', 'Hosting & Domain'), ('godaddy', 'Hosting & Domain'),
('paper', 'Office Stationery'), ('pen', 'Office Stationery'), ('stationery', 'Office Stationery'), ('xerox', 'Office Stationery'), ('print out', 'Office Stationery'),
('repair', 'Office Maintenance'), ('maintenance', 'Office Maintenance'), ('ac service', 'Office Maintenance'), ('cleaning', 'Office Maintenance'), ('safai', 'Office Maintenance'),
('furniture', 'Office Equipment & Furniture'), ('chair', 'Office Equipment & Furniture'), ('table', 'Office Equipment & Furniture'),
('advertisement', 'Marketing & Advertising'), ('marketing', 'Marketing & Advertising'), ('promotion', 'Marketing & Advertising'), ('facebook ads', 'Marketing & Advertising'),
('google ads', 'Marketing & Advertising'), ('banner', 'Marketing & Advertising'), ('pamphlet', 'Marketing & Advertising'), ('visiting card', 'Marketing & Advertising'),
('chatgpt', 'Software & AI'), ('gemini', 'Software & AI'), ('software', 'Software & AI'), ('subscription', 'Software & AI'),
('bank charge', 'Bank Charges & Interest'), ('interest', 'Bank Charges & Interest'), ('razorpay', 'Payment Gateway Charges'),
('emi', 'EMI / Loan'), ('loan', 'EMI / Loan'), ('હપ્તો', 'EMI / Loan'),
('gst', 'GST & Government'), ('tax', 'GST & Government'), ('challan', 'GST & Government'),
('ghar', 'Money Given Home'), ('ઘરે આપ્યા', 'Money Given Home'), ('home rent', 'Home Rent'), ('ghar bhadu', 'Home Rent'),
('gas', 'Home Gas & Water'), ('cylinder', 'Home Gas & Water'), ('ગેસ', 'Home Gas & Water'),
('grocery', 'Home Groceries'), ('kariyana', 'Home Groceries'), ('vegetable', 'Home Groceries'), ('શાકભાજી', 'Home Groceries'), ('કરિયાણું', 'Home Groceries'),
('dth', 'Home Mobile & DTH'), ('school', 'School & Children'), ('fees', 'School & Children'), ('tuition', 'School & Children'), ('ફી', 'School & Children'),
('doctor', 'Medical / Personal'), ('medicine', 'Medical / Personal'), ('hospital', 'Medical / Personal'), ('દવા', 'Medical / Personal'),
('lic', 'Insurance / LIC'), ('insurance', 'Insurance / LIC'), ('policy', 'Insurance / LIC'),
('clothes', 'Home Shopping & Clothes'), ('kapda', 'Home Shopping & Clothes'), ('કપડાં', 'Home Shopping & Clothes'),
('wedding', 'Functions & Weddings'), ('marriage', 'Functions & Weddings'), ('લગ્ન', 'Functions & Weddings'),
('gift', 'Gifts & Family'), ('ભેટ', 'Gifts & Family')
ON DUPLICATE KEY UPDATE category = VALUES(category);
