<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  EXPENSES - staff record today's spending only (no date change), the note
//  picks the category, and the owner can correct old wrong categories with
//  Undo. Only the category ever changes on an old entry.
// ============================================================================
require_once __DIR__ . '/../includes/expense_ai.php';
require_once __DIR__ . '/../includes/entry_save.php';

t_group('Expense note picks the category');
t_eq('petrol for the bike is vehicle spending', expense_guess_keywords('Petrol bike 200'), 'Petrol & Vehicle');
t_eq('a Gujarati note works too', expense_guess_keywords('દુકાન ભાડું નવેમ્બર'), 'Office Rent');
t_eq('salary is salary', expense_guess_keywords('Ramesh salary October'), 'Salary & Wages');
t_eq('"pen" inside another word does not fire', expense_guess_keywords('open box expense'), null);
t_eq('the longer keyword wins (ghar bhadu is home rent, not money home)', expense_guess_keywords('ghar bhadu'), 'Home Rent');
t_eq('a two-letter note is too short to guess', expense_guess('ok'), null);
// the shop's own notes, as staff really type them (Expenses list, 09-11-2026)
foreach ([
    'Pani ni botal New office' => 'Tea & Water', 'Rajnikadha and soda' => 'Owner Drawings / Personal',
    'Petoal in ak scooty' => 'Petrol & Vehicle', 'Printer bhadu Dinesh bhai aangadiya' => 'Courier & Transport',
    'Diwali nasto home' => 'Home Groceries', 'Nasto' => 'Food & Meals', 'Khetabha upad gp' => 'Staff Advance',
    'Nagesh upad and selery' => 'Salary & Wages', 'Home ligh bill' => 'Home Electricity', 'Light bill' => 'Electricity',
    'Papa recharge' => 'Home Mobile & DTH', 'Airtel broadband' => 'Internet & Telecom', 'Contado vps' => 'Hosting & Domain',
    'Ai claude' => 'Software & AI', 'Credit card vyaj' => 'Bank Charges & Interest', 'Emi scooty' => 'EMI / Loan',
    'Office rent and agreement' => 'Office Rent', 'Gadi sarvice Akshay bhai' => 'Vehicle Repair', 'Pancher' => 'Vehicle Repair',
    'Office staf clothes' => 'Customer/Staff Expense', 'Lunch with zala sir' => 'Customer/Staff Expense', 'Pani home' => 'Home Gas & Water',
    'Water bill in home' => 'Home Gas & Water', 'Gas cylinder' => 'Home Gas & Water', 'Mom ne aapiya' => 'Money Given Home',
    'Pooja na kapda' => 'Home Shopping & Clothes', 'Randel lota and other kurch' => 'Functions & Weddings',
    'Vraj hospital pooja teblet' => 'Medical / Personal', 'Flaxibal pipe' => 'Office Maintenance', 'Stiker sheet' => 'Office Stationery',
    'Facebook advertising google rewwie and menu.akdwk.in' => 'Marketing & Advertising', 'Payment gateway' => 'Payment Gateway Charges',
    'My bike' => 'Petrol & Vehicle', 'Dineshbhai 3 printer send rajkot' => 'Courier & Transport',
] as $note => $want) t_eq("\"$note\" → $want", expense_guess_keywords($note), $want);
t_eq('a note that says nothing clear keeps its category', expense_guess_keywords('Kartik ne aapiya'), null);

t_group('Staff cannot change the expense date');
$staff = ['id' => (int)val('SELECT MIN(id) FROM users'), 'perms' => ['expenses.add'], 'location_id' => (int)val('SELECT MIN(id) FROM locations')];
$admin = ['perms' => ['*']] + $staff;
$old = date('Y-m-d', strtotime('-5 days'));
$_POST = ['exp_date' => $old, 'category' => 'Tea & Water', 'amount' => '30', 'mode' => 'cash', 'notes' => 'chai'];
$r = expense_save_form($staff);
t_eq('a staff entry dated 5 days back is saved with today\'s date', val('SELECT exp_date FROM expenses WHERE id = ?', [$r['id']]), today());
$_POST = ['exp_date' => $old, 'category' => 'Tea & Water', 'amount' => '30', 'mode' => 'cash', 'notes' => 'chai'];
$r = expense_save_form($admin);
t_eq('the admin can still save on another date', val('SELECT exp_date FROM expenses WHERE id = ?', [$r['id']]), $old);
$_POST = ['exp_date' => today(), 'category' => 'General', 'amount' => '500', 'mode' => 'cash', 'notes' => 'petrol bike'];
$r = expense_save_form($staff);
t_eq('no category picked: the note decides', val('SELECT category FROM expenses WHERE id = ?', [$r['id']]), 'Petrol & Vehicle');
$_POST = ['exp_date' => today(), 'category' => 'Food & Meals', 'amount' => '120', 'mode' => 'cash', 'notes' => 'chai'];
$r = expense_save_form($staff);
t_eq('a category the staff picked is kept as picked', val('SELECT category FROM expenses WHERE id = ?', [$r['id']]), 'Food & Meals');
$_POST = ['exp_date' => today(), 'category' => '', 'amount' => '75', 'mode' => 'cash', 'notes' => 'Kartik ne aapiya'];
$r = expense_save_form($staff);
t_eq('nothing picked and nothing in the note: Business Miscellaneous, never Office Rent', val('SELECT category FROM expenses WHERE id = ?', [$r['id']]), 'Business Miscellaneous');
$src = file_get_contents(__DIR__ . '/../expenses.php');
t_ok('a new expense starts with no category picked (Office Rent was the silent default)', strpos($src, '<option value="">— from the note —</option>') !== false);
t_ok('an edit by staff keeps the saved date', strpos($src, "if (!is_full_admin()) \$_POST['exp_date'] = \$eid ? (string)val('SELECT exp_date FROM expenses WHERE id = ?', [\$eid]) : today();") !== false);

t_group('Old wrong categories: owner applies, Undo restores, only the category moves');
q("INSERT INTO expenses (exp_date, category, amount, mode, notes, created_by, location_id) VALUES (?, 'Tea & Water', 7000, 'cash', 'Mahesh salary', ?, ?)", [today(), $staff['id'], $staff['location_id']]);
$eid = (int)val('SELECT MAX(id) FROM expenses');
$before = row('SELECT exp_date, amount, mode FROM expenses WHERE id = ?', [$eid]);
$cand = array_values(array_filter(expense_fix_candidates(), fn($c) => (int)$c['id'] === $eid));
t_eq('the wrongly filed salary is proposed for Salary & Wages', $cand[0]['suggest'] ?? null, 'Salary & Wages');
t_eq('nothing changed just by looking', val('SELECT category FROM expenses WHERE id = ?', [$eid]), 'Tea & Water');
t_eq('an unknown category is refused', expense_fix_apply([$eid => 'Made Up']), 0);
t_eq('Apply moves it', expense_fix_apply([$eid => 'Salary & Wages']), 1);
t_eq('...to the right category', val('SELECT category FROM expenses WHERE id = ?', [$eid]), 'Salary & Wages');
t_eq('...amount, date and mode untouched', row('SELECT exp_date, amount, mode FROM expenses WHERE id = ?', [$eid]), $before);
expense_fix_undo();
t_eq('Undo puts it back', val('SELECT category FROM expenses WHERE id = ?', [$eid]), 'Tea & Water');
$lastMonth = date('Y-m-d', strtotime(date('Y-m-01') . ' -1 month'));
q("INSERT INTO expenses (exp_date, category, amount, mode, notes, created_by, location_id) VALUES (?, 'Office Rent', 20, 'cash', 'Pani ni botal', ?, ?)", [$lastMonth, $staff['id'], $staff['location_id']]);
$old = (int)val('SELECT MAX(id) FROM expenses');
$ids = fn($c) => array_map('intval', array_column($c, 'id'));
t_ok("this month's list does not drag in last month's entries", !in_array($old, $ids(expense_fix_candidates(date('Y-m-01'), date('Y-m-t')))));
t_ok("last month's list has it", in_array($old, $ids(expense_fix_candidates($lastMonth, date('Y-m-t', strtotime($lastMonth))))));
set_setting('expense_fix_once_done', '');
t_ok('the one-time fix at Update files old entries by their note', expense_fix_once() >= 2);
t_eq('...the water bottle left Office Rent', val('SELECT category FROM expenses WHERE id = ?', [$old]), 'Tea & Water');
t_eq('...and it never runs a second time', expense_fix_once(), 0);
t_ok('...and Undo is still there for it', setting('expense_fix_undo') !== '');
$src = file_get_contents(__DIR__ . '/../includes/dbmigrate.php');
t_ok('Update (run all migrations) runs it', strpos($src, 'expense_fix_once();') !== false);
$_POST = [];
