-- Upgrade v15 -> v16: structured payment-to-bill allocations, so a payment
-- linked to specific sale/purchase bills can be correctly reversed when
-- deleted or edited (previously only a human-readable text note recorded
-- which bills a payment was linked to, so deleting a payment left the
-- bill's own `paid` amount permanently out of sync with reality).
CREATE TABLE IF NOT EXISTS payment_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_id INT NOT NULL,
  ref_type ENUM('sale','purchase') NOT NULL,
  ref_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  KEY idx_pa_payment (payment_id),
  KEY idx_pa_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
