-- v65: a serial that is in stock must be in stock SOMEWHERE.
--
-- item_serials.location_id says which shelf a piece is on, and every screen
-- that offers serials to pick - billing, sales return, purchase return,
-- handover, transfer - asks for the serials AT the location being worked at.
-- The item page does not: it lists every serial of the item, whatever the
-- location.
--
-- So a row saved with location 0 or NULL is in stock and un-pickable at the
-- same time. The item page shows it "In stock" and counts it in the quantity,
-- while every picker answers "આ લોકેશનમાં આ આઇટમનો કોઈ સિરિયલ સ્ટોકમાં નથી" -
-- and there is no screen that can put it right, because nothing can pick what
-- nothing can see. The same happens to a serial pointing at a location that
-- was later deleted.
--
-- serial_put_in_stock() now resolves the location for every write, so no new
-- one can appear. This puts the existing ones back on a real shelf, by the
-- same rule: where this item's stock already sits, else the first active
-- location. Both statements are self-cancelling - after they run nothing
-- matches them any more, so re-running the migration does nothing.

-- 1. the item has stock somewhere real: the piece belongs with it
UPDATE item_serials s
   SET s.location_id = (SELECT st.location_id FROM stock st
                          JOIN locations l ON l.id = st.location_id
                         WHERE st.item_id = s.item_id ORDER BY st.qty DESC LIMIT 1)
 WHERE s.status = 'in_stock'
   AND (s.location_id IS NULL OR s.location_id = 0
        OR NOT EXISTS (SELECT 1 FROM locations l2 WHERE l2.id = s.location_id))
   AND EXISTS (SELECT 1 FROM stock st2 JOIN locations l3 ON l3.id = st2.location_id
                WHERE st2.item_id = s.item_id);

-- 2. nothing else to go on: the shop's own counter
UPDATE item_serials s
   SET s.location_id = (SELECT l.id FROM locations l WHERE l.is_active = 1 ORDER BY l.id LIMIT 1)
 WHERE s.status = 'in_stock'
   AND (s.location_id IS NULL OR s.location_id = 0
        OR NOT EXISTS (SELECT 1 FROM locations l2 WHERE l2.id = s.location_id))
   AND EXISTS (SELECT 1 FROM locations l4 WHERE l4.is_active = 1);
