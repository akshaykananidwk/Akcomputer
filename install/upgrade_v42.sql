-- Estimate auto follow-up: the daily cron WhatsApps a gentle "have you
-- decided?" nudge N days after an estimate was given (once per estimate).
ALTER TABLE estimates ADD COLUMN followup_sent_at DATE NULL;
