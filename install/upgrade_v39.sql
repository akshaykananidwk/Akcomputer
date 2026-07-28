-- Item custom fields (description points) can now be marked per field as
-- customer-facing (shown on the bill view, print, PDF and WhatsApp copy)
-- or internal-only (visible just to logged-in staff on screen).
ALTER TABLE item_custom_fields ADD COLUMN show_on_print TINYINT(1) NOT NULL DEFAULT 1;
