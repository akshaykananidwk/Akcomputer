-- v48: customers can accept/reject quotations from the WhatsApp portal
ALTER TABLE estimates MODIFY status ENUM('open','accepted','rejected','converted','cancelled') NOT NULL DEFAULT 'open';
