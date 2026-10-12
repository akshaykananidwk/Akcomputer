-- v91: the phone app writes purchases and expenses too, offline. Each carries
-- the id the phone made, so a purchase sent twice (the reply was lost) is
-- recognised and never stocks the goods twice.
ALTER TABLE purchases ADD COLUMN client_uuid CHAR(36) NULL;
ALTER TABLE purchases ADD UNIQUE KEY uk_purchase_client_uuid (client_uuid);
ALTER TABLE expenses ADD COLUMN client_uuid CHAR(36) NULL;
ALTER TABLE expenses ADD UNIQUE KEY uk_expense_client_uuid (client_uuid);
ALTER TABLE expense_requests ADD COLUMN client_uuid CHAR(36) NULL;
ALTER TABLE expense_requests ADD UNIQUE KEY uk_expreq_client_uuid (client_uuid);
