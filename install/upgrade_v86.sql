-- v86: staff dates of birth.
--
-- The owner wants to know a day ahead when someone in the team has a
-- birthday, so a wish (or a cake) is not an afterthought. Optional: a staff
-- member without a date simply never shows up.
ALTER TABLE users ADD COLUMN dob DATE NULL DEFAULT NULL AFTER mobile;
