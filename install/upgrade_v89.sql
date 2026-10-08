-- v89: staff & payroll, partners and suppliers, tax and books, security,
-- and outside connections (functions 51-100). Every table is new; the few
-- columns added to users/parties are optional and default to "nothing".

-- ---------- staff ----------
ALTER TABLE users ADD COLUMN salary_monthly DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN shift_start TIME NULL DEFAULT NULL;
ALTER TABLE users ADD COLUMN shift_end TIME NULL DEFAULT NULL;
ALTER TABLE users ADD COLUMN weekly_off TINYINT NULL DEFAULT NULL;      -- 0 = Sunday ... 6 = Saturday
ALTER TABLE users ADD COLUMN joined_on DATE NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    att_date DATE NOT NULL,
    in_at DATETIME NULL, out_at DATETIME NULL,
    in_lat DECIMAL(10,7) NULL, in_lng DECIMAL(10,7) NULL, out_lat DECIMAL(10,7) NULL, out_lng DECIMAL(10,7) NULL,
    in_photo VARCHAR(200) NULL, out_photo VARCHAR(200) NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'present',      -- present / half / absent / leave (set by the owner)
    note VARCHAR(200) NOT NULL DEFAULT '',
    UNIQUE KEY uq_att (user_id, att_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    from_date DATE NOT NULL, to_date DATE NOT NULL,
    paid TINYINT(1) NOT NULL DEFAULT 1,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(10) NOT NULL DEFAULT 'pending',      -- pending / approved / rejected
    decided_by INT NULL, decided_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_leave_user (user_id, from_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payslips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    month CHAR(7) NOT NULL,                            -- 2026-10
    days_in_month INT NOT NULL, paid_days DECIMAL(5,1) NOT NULL,
    salary DECIMAL(12,2) NOT NULL, earned DECIMAL(12,2) NOT NULL, commission DECIMAL(12,2) NOT NULL DEFAULT 0,
    advance_deduct DECIMAL(12,2) NOT NULL DEFAULT 0, other_deduct DECIMAL(12,2) NOT NULL DEFAULT 0,
    net DECIMAL(12,2) NOT NULL,
    expense_id INT NULL, paid_at DATETIME NULL,
    created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slip (user_id, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_docs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, kind VARCHAR(40) NOT NULL, file VARCHAR(200) NOT NULL,
    created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_doc_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_training (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, task VARCHAR(150) NOT NULL,
    done_at DATETIME NULL, done_by INT NULL,
    UNIQUE KEY uq_train (user_id, task)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_user INT NOT NULL, to_user INT NULL,           -- NULL = everyone
    body VARCHAR(1000) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_chat_to (to_user, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chat_reads (
    user_id INT PRIMARY KEY, last_id INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- suppliers, customers, partners ----------
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_no VARCHAR(30) NOT NULL,
    party_id INT NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'sent',         -- sent / accepted / received / cancelled
    notes VARCHAR(255) NOT NULL DEFAULT '',
    share_token VARCHAR(40) NOT NULL,
    supplier_note VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, accepted_at DATETIME NULL,
    KEY idx_po_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL, item_id INT NOT NULL, qty DECIMAL(12,2) NOT NULL, price DECIMAL(12,2) NOT NULL DEFAULT 0,
    KEY idx_poi (po_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS queue_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tok_date DATE NOT NULL, token_no INT NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT '', mobile VARCHAR(20) NOT NULL DEFAULT '', purpose VARCHAR(120) NOT NULL DEFAULT '',
    status VARCHAR(10) NOT NULL DEFAULT 'waiting',       -- waiting / called / done / left
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, called_at DATETIME NULL,
    UNIQUE KEY uq_tok (tok_date, token_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL, assigned_to INT NULL,
    address VARCHAR(255) NOT NULL DEFAULT '',
    otp_hash VARCHAR(100) NOT NULL DEFAULT '',
    status VARCHAR(12) NOT NULL DEFAULT 'assigned',      -- assigned / out / delivered / failed
    photo VARCHAR(200) NULL, lat DECIMAL(10,7) NULL, lng DECIMAL(10,7) NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, delivered_at DATETIME NULL,
    KEY idx_deliv_user (assigned_to, status), KEY idx_deliv_sale (sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS partners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL, share_pct DECIMAL(5,2) NOT NULL, mobile VARCHAR(20) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consent_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    party_id INT NOT NULL, kind VARCHAR(20) NOT NULL,   -- marketing / collection
    given TINYINT(1) NOT NULL, source VARCHAR(40) NOT NULL DEFAULT '',
    by_user INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_consent_party (party_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- books ----------
CREATE TABLE IF NOT EXISTS loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lender VARCHAR(120) NOT NULL, principal DECIMAL(14,2) NOT NULL, rate_pct DECIMAL(6,3) NOT NULL,
    start_date DATE NOT NULL, months INT NOT NULL, emi DECIMAL(12,2) NOT NULL,
    notes VARCHAR(255) NOT NULL DEFAULT '', is_closed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS loan_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL, n INT NOT NULL, due_date DATE NOT NULL,
    emi DECIMAL(12,2) NOT NULL, interest DECIMAL(12,2) NOT NULL, principal_part DECIMAL(12,2) NOT NULL,
    paid_on DATE NULL, expense_id INT NULL,
    UNIQUE KEY uq_loan_n (loan_id, n)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL, category VARCHAR(60) NOT NULL DEFAULT '',
    bought_on DATE NOT NULL, cost DECIMAL(14,2) NOT NULL, rate_pct DECIMAL(5,2) NOT NULL DEFAULT 15,
    disposed_on DATE NULL, notes VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expense_budgets (
    category VARCHAR(80) PRIMARY KEY, monthly DECIMAL(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- security ----------
CREATE TABLE IF NOT EXISTS recycle_bin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(120) NOT NULL, sets LONGTEXT NOT NULL,
    user_id INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, restored_at DATETIME NULL,
    KEY idx_bin_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS known_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, device_hash CHAR(64) NOT NULL, label VARCHAR(255) NOT NULL DEFAULT '',
    first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME NULL,
    UNIQUE KEY uq_dev (user_id, device_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webauthn_creds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, cred_id VARCHAR(255) NOT NULL, public_key TEXT NOT NULL,
    sign_count INT UNSIGNED NOT NULL DEFAULT 0, name VARCHAR(80) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, last_used DATETIME NULL,
    UNIQUE KEY uq_cred (cred_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- connections ----------
CREATE TABLE IF NOT EXISTS upi_inbox (
    id INT AUTO_INCREMENT PRIMARY KEY,
    amount DECIMAL(12,2) NOT NULL, payer VARCHAR(120) NOT NULL DEFAULT '', ref VARCHAR(60) NOT NULL,
    raw VARCHAR(500) NOT NULL DEFAULT '', status VARCHAR(10) NOT NULL DEFAULT 'new',   -- new / recorded / ignored
    party_id INT NULL, payment_id INT NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_upi_ref (ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bank_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id INT NOT NULL, line_date DATE NOT NULL, description VARCHAR(255) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,                       -- + money in, - money out
    ref VARCHAR(80) NOT NULL DEFAULT '', fingerprint CHAR(40) NOT NULL,
    matched_type VARCHAR(20) NULL, matched_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bank_line (fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL, provider VARCHAR(20) NOT NULL DEFAULT 'shiprocket',
    order_ref VARCHAR(60) NOT NULL DEFAULT '', awb VARCHAR(60) NOT NULL DEFAULT '', courier VARCHAR(80) NOT NULL DEFAULT '',
    status VARCHAR(40) NOT NULL DEFAULT 'created', tracking_url VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
    KEY idx_ship_sale (sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- permissions ----------
-- seeing a customer's full mobile number is now its own permission; every
-- role that could see parties keeps seeing them exactly as before
UPDATE roles SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'parties.contact')
 WHERE permissions LIKE '%"parties.view"%' AND permissions NOT LIKE '%parties.contact%';
-- the shop's CA: can read the books, cannot change anything
INSERT INTO roles (name, permissions, is_system)
SELECT 'CA / Accountant (view only)',
       '["dashboard.view","sales.view","sales.all","estimates.view","sales_return.view","purchases.view","purchases.all","purchase_return.view","items.view","items.cost","parties.view","parties.contact","stock.view","payments.view","cheques.view","dayclose.view","expenses.view","challans.view","reports.view","reports.profit","reports.gst","reports.accounting","accounting.view","companies.view","books.view"]', 0
 WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'CA / Accountant (view only)');

-- staff attendance & payroll comes with Pro (Premium already has everything)
UPDATE plans SET features = JSON_ARRAY_APPEND(features, '$', 'hr') WHERE code = 'pro' AND features NOT LIKE '%"hr"%' AND features NOT LIKE '%"*"%';
