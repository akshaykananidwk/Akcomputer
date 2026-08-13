-- v54: indexes for the hot queries the audit flagged. Read-only change - no
-- data is touched, only lookup structures are added, so it is safe to re-run.
-- The migration runner treats "duplicate key name" (1061) as already-applied,
-- which is what makes this idempotent; a real failure is reported in the
-- migration log instead of being swallowed.
--
-- Naming follows the existing convention: idx_<table abbrev>_<columns>.

-- payments: the cash book, the day's cash totals and every date-ranged money
-- report scan the whole table today.
ALTER TABLE payments ADD INDEX idx_pay_date (pay_date);
ALTER TABLE payments ADD INDEX idx_pay_created_date (created_by, pay_date);

-- sale_items: "where has this product been sold", how many bills used an item,
-- and the best-seller roll-up all filter or group by item_id. qty is carried
-- in the index on purpose: it makes the best-seller SUM(qty) roll-up read the
-- index alone instead of fetching 75,000 rows (measured 85ms -> 14ms).
ALTER TABLE sale_items ADD INDEX idx_si_item (item_id, qty);

-- sales: the receivables list and the outstanding total filter on status and
-- order by due date.
ALTER TABLE sales ADD INDEX idx_sale_status_due (status, due_date);

-- activity_log: the biggest table in the shop. The AI-usage counters, the
-- birthday-wish guard and the activity report all filter on action and then
-- narrow by time.
ALTER TABLE activity_log ADD INDEX idx_log_action_time (action, created_at);

-- estimates: the dashboard's open-quotation card and the follow-up cron.
ALTER TABLE estimates ADD INDEX idx_est_status_date (status, estimate_date);

-- web_orders: the dashboard's new-order strip, the monthly count and the
-- per-dealer order roll-up.
ALTER TABLE web_orders ADD INDEX idx_wo_status (status, id);
ALTER TABLE web_orders ADD INDEX idx_wo_created (created_at);
ALTER TABLE web_orders ADD INDEX idx_wo_account (web_account_id);
