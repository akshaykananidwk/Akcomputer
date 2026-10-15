-- v94: the books in double entry. Adds the accounts every document now posts
-- to (GST, walk-in customers, staff advances, loans, drawings, opening
-- balances...) and lets the owner say what a cash / bank adjustment was.
-- Changes no existing entry: an adjustment nobody has classified stays under
-- "not yet classified" until the owner picks what it was.
ALTER TABLE money_transfers ADD COLUMN counter VARCHAR(12) NULL;
INSERT IGNORE INTO chart_of_accounts (code, name, type, is_system, sort_order) VALUES
('1150', 'Money not in a bank yet', 'asset', 1, 25),
('1210', 'Walk-in customers', 'asset', 1, 31),
('1400', 'GST input credit', 'asset', 1, 42),
('1500', 'Staff advances', 'asset', 1, 44),
('1900', 'Contra settlements', 'asset', 1, 48),
('2100', 'GST collected', 'liability', 1, 52),
('2200', 'Loans', 'liability', 1, 54),
('3010', 'Opening balances', 'equity', 1, 62),
('3090', 'Adjustments - not yet classified', 'equity', 1, 64),
('3100', 'Drawings', 'equity', 1, 66),
('4200', 'Other income', 'income', 1, 86),
('4300', 'Warranty credit notes', 'income', 1, 87),
('4400', 'Discount received', 'income', 1, 88),
('5100', 'Stock adjustments', 'expense', 1, 92),
('5200', 'Discount allowed', 'expense', 1, 93),
('5300', 'Loan interest', 'expense', 1, 94),
('5400', 'Cash / bank shortage or excess', 'expense', 1, 95),
('6000', 'Operating expenses', 'expense', 1, 96);
