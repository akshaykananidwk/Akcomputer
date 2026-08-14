-- v57: AI cost accounting.
--
-- api_usage already counts tokens in and out. What it could not answer is
-- "what did this month's AI cost me", which is the question a monthly budget
-- has to be enforced against. Cost is stored in PAISE as an integer, not
-- rupees as a decimal: a single Gemini call costs a fraction of a paisa, and
-- accumulating thousands of tiny floats is exactly how a total drifts.
--
-- The rate itself is NOT hardcoded anywhere. Providers change their pricing
-- and this code has no way to know what the shop is actually billed, so the
-- per-million-token rates are settings the owner fills in from their own bill.
-- Left empty, token counts are still recorded and the call-count cap still
-- protects them - only the rupee figure is withheld rather than invented.

ALTER TABLE api_usage ADD COLUMN cost_paise INT NOT NULL DEFAULT 0;
ALTER TABLE api_usage ADD COLUMN feature VARCHAR(40) NOT NULL DEFAULT '';

-- "what has AI cost this month" and "how many calls has this feature made"
-- are both read on every guarded call, so they must not scan the table.
ALTER TABLE api_usage ADD INDEX idx_usage_service_time (service, created_at);
