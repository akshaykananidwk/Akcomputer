-- Upgrade v24 -> v25: OCR-assisted purchase entry - upload a photo of a
-- vendor bill, extract text via a cloud OCR API, search the shop's own
-- item catalog (by model number / barcode / name) for matches, and hand
-- off matched items straight into the New Purchase form (reusing the
-- existing Low Stock "Create Purchase for Selected" prefill mechanism).
-- No local OCR engine is used - shared hosting can't have one installed,
-- so this calls out to OCR.space with an API key configured in Settings.

ALTER TABLE purchases ADD COLUMN bill_photo VARCHAR(255) DEFAULT '';
