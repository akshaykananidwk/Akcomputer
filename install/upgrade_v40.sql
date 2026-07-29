-- Expenses got an Edit action (expenses.php previously only had add and
-- delete). Every role that can already add expenses gets expenses.edit,
-- and the Manager role gets full day-to-day expense rights (view/add/edit)
-- so a manager can correct a wrongly-entered expense without the admin.
UPDATE roles SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'expenses.edit')
 WHERE JSON_VALID(permissions)
   AND JSON_SEARCH(permissions, 'one', 'expenses.add') IS NOT NULL
   AND JSON_SEARCH(permissions, 'one', 'expenses.edit') IS NULL
   AND JSON_SEARCH(permissions, 'one', '*') IS NULL;

UPDATE roles SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'expenses.view')
 WHERE name = 'Manager' AND JSON_VALID(permissions)
   AND JSON_SEARCH(permissions, 'one', 'expenses.view') IS NULL
   AND JSON_SEARCH(permissions, 'one', '*') IS NULL;
UPDATE roles SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'expenses.add')
 WHERE name = 'Manager' AND JSON_VALID(permissions)
   AND JSON_SEARCH(permissions, 'one', 'expenses.add') IS NULL
   AND JSON_SEARCH(permissions, 'one', '*') IS NULL;
UPDATE roles SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'expenses.edit')
 WHERE name = 'Manager' AND JSON_VALID(permissions)
   AND JSON_SEARCH(permissions, 'one', 'expenses.edit') IS NULL
   AND JSON_SEARCH(permissions, 'one', '*') IS NULL;
