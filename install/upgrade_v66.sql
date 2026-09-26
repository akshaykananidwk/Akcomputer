-- v66: દિવસનું ક્લોઝિંગ - the night's cash count.
--
-- Every shop that takes cash counts the drawer before shutting. Until now
-- there was nowhere to write that count down, so "ચોપડામાં કેટલા હોવા જોઈએ"
-- and "ખરેખર કેટલા છે" were never put side by side, and a ₹500 that walked
-- off on a Tuesday was found - if ever - at the end of the month with no way
-- left to work out which day it went.
--
-- One row per day. counted is what was physically in the drawer, expected is
-- what the books said at that moment (the same cash_in_hand rule the Cash &
-- Bank page uses, as of that date), and diff is the gap. The gap is NOT
-- silently written into the books: money the shop cannot explain must stay
-- visible. The owner can choose to post a correction, and adjust_id then
-- points at the money_transfers row that did it, so it can be traced back.
--
-- denoms holds the note-by-note count as "500x4,200x1,100x7" - the slip the
-- counting was done on. It is kept because "we were ₹500 short" is a very
-- different conversation from "we were ₹487 short".

CREATE TABLE IF NOT EXISTS day_closes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  close_date DATE NOT NULL,
  counted DECIMAL(12,2) NOT NULL DEFAULT 0,
  expected DECIMAL(12,2) NOT NULL DEFAULT 0,
  diff DECIMAL(12,2) NOT NULL DEFAULT 0,
  denoms VARCHAR(255) NULL,
  notes VARCHAR(255) NULL,
  adjust_id INT NULL,
  closed_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_day_close (close_date),
  INDEX idx_day_close_date (close_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
