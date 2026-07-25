-- AI product enrichment: items get an auto-fillable description; photos and
-- category were already columns. Filled by ai_enrich.php via Gemini + Google
-- image search.
ALTER TABLE items ADD COLUMN description TEXT NULL;
