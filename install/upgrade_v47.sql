-- v47: category tree (sub-categories) for the AI catalog organizer
ALTER TABLE categories ADD COLUMN parent_id INT NULL;
