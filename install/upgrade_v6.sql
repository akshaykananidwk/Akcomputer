-- Upgrade v5 -> v6: auto overdue reminders tracking
ALTER TABLE sales ADD COLUMN last_reminder DATE DEFAULT NULL;
