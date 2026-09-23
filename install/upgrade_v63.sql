-- v63: the shop's own expense category list.
--
-- The dropdown had eleven generic names; the owner wants the twenty-seven he
-- actually spends money under. Adding them is easy. The part that matters is
-- the history: every report groups expenses by the category STRING, so if the
-- old names simply disappeared from the dropdown the books would carry "Rent"
-- and "Office Rent" as two separate lines forever, and every year-on-year
-- comparison would break at the day the list changed.
--
-- So the old names are folded into the new ones. Only exact matches are
-- touched - anything the owner typed himself is left alone.
--
-- General and Other both become Business Miscellaneous: they were two names
-- for the same "don't know where to put this" pile.
--
-- Tea/Food is mapped to Tea & Water rather than Food & Meals because in this
-- shop it was mostly the daily tea; anything that was really a meal can be
-- moved by editing the entry.

UPDATE expenses SET category = 'Office Rent'                 WHERE category = 'Rent';
UPDATE expenses SET category = 'Salary & Wages'              WHERE category = 'Salary';
UPDATE expenses SET category = 'Internet & Telecom'          WHERE category = 'Internet';
UPDATE expenses SET category = 'Courier & Transport'         WHERE category = 'Transport';
UPDATE expenses SET category = 'Tea & Water'                 WHERE category = 'Tea/Food';
UPDATE expenses SET category = 'Office Stationery'           WHERE category = 'Stationery';
UPDATE expenses SET category = 'Office Maintenance'          WHERE category = 'Repair/Maintenance';
UPDATE expenses SET category = 'Marketing & Advertising'     WHERE category = 'Marketing';
UPDATE expenses SET category = 'Business Miscellaneous'      WHERE category IN ('General', 'Other');

-- the AI note-to-category suggester points at the old names too
UPDATE expense_category_keywords SET category = 'Office Rent'             WHERE category = 'Rent';
UPDATE expense_category_keywords SET category = 'Salary & Wages'          WHERE category = 'Salary';
UPDATE expense_category_keywords SET category = 'Internet & Telecom'      WHERE category = 'Internet';
UPDATE expense_category_keywords SET category = 'Courier & Transport'     WHERE category = 'Transport';
UPDATE expense_category_keywords SET category = 'Tea & Water'             WHERE category = 'Tea/Food';
UPDATE expense_category_keywords SET category = 'Office Stationery'       WHERE category = 'Stationery';
UPDATE expense_category_keywords SET category = 'Office Maintenance'      WHERE category = 'Repair/Maintenance';
UPDATE expense_category_keywords SET category = 'Marketing & Advertising' WHERE category = 'Marketing';
UPDATE expense_category_keywords SET category = 'Business Miscellaneous'  WHERE category IN ('General', 'Other');
