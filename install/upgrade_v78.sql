-- v78: the shop decides what the phone says, not the software.
--
-- Three things the owner asked for and could not do:
--
--   1. "જય દ્વારકાધીશ" first, on every call. That is the shop's own greeting
--      and no software has any business fixing it - so it is a line like any
--      other, editable and switchable off.
--
--   2. The caller's own name spoken back to them. The line for it already
--      existed but was NEVER heard: it is one sentence per customer, so it
--      cannot be made in advance the way the fixed lines are, and the live
--      path is forbidden from making speech while somebody is on the line.
--      It fell back to the nameless greeting on every single call. The
--      greetings are now made ahead of time, a few at a time, for the people
--      likely to ring - and the moment one is ready the caller hears it.
--
--   3. Every line editable, and each menu option switchable. The menu
--      sentence is no longer one fixed blob: it is built from the options
--      that are switched on, so turning one off removes it from what is
--      spoken as well as from what the keypad accepts. A shop with no
--      delivery has no business offering "press 5 for your order".
--
-- The edited text lives in one settings row per language. No table: it is a
-- handful of sentences, read on every call, and a JSON column the settings
-- screen already knows how to store beats a table nothing else joins to.
--
-- Nothing is copied into these rows. Empty means "use the wording the
-- software ships with", so an upgrade that improves a default sentence still
-- reaches a shop that never edited it, and clearing a box restores it.
INSERT IGNORE INTO settings (name, value) VALUES
    ('voice_lines_gu', ''),      -- {"key": "what this shop says instead", ...}
    ('voice_lines_hi', ''),
    ('voice_lines_en', ''),
    -- Which keypad options are offered, in the order they are read out.
    -- Empty means all of them, which is what every shop had before today.
    ('voice_menu_opts', ''),     -- e.g. "1,3,9" - only account, complaint, person
    -- Greetings made ahead of the call, so a caller hears their own name.
    ('voice_greet_ahead', '1'),
    ('voice_greet_per_run', '10');
