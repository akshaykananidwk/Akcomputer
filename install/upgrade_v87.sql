-- v87: the software as a service - many shops, one code base.
--
-- Each shop that signs up gets a database of its OWN; these tables live in
-- the owner's (platform) database and only say which shop lives where, on
-- which plan, paid until when. A shop's bills, parties and stock never sit
-- in a shared table, so one shop can never see another's data through a
-- forgotten WHERE.
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    price_month DECIMAL(10,2) NOT NULL DEFAULT 0,
    price_year DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_users INT NOT NULL DEFAULT 0,          -- 0 = no limit
    max_bills_month INT NOT NULL DEFAULT 0,
    max_wa_month INT NOT NULL DEFAULT 0,
    max_locations INT NOT NULL DEFAULT 0,
    features TEXT NULL,                        -- JSON list of module codes this plan opens
    white_label TINYINT(1) NOT NULL DEFAULT 0, -- may hide "Powered by"
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS resellers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    mobile VARCHAR(20) NOT NULL DEFAULT '',
    email VARCHAR(120) NOT NULL DEFAULT '',
    code VARCHAR(20) NOT NULL UNIQUE,
    commission_pct DECIMAL(5,2) NOT NULL DEFAULT 20,
    password VARCHAR(255) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    owner_name VARCHAR(120) NOT NULL DEFAULT '',
    owner_mobile VARCHAR(20) NOT NULL DEFAULT '',
    owner_email VARCHAR(120) NOT NULL DEFAULT '',
    business_type VARCHAR(30) NOT NULL DEFAULT 'general',
    domain VARCHAR(190) NOT NULL UNIQUE,       -- slug.platform-domain
    custom_domain VARCHAR(190) NULL UNIQUE,    -- the shop's own domain, once pointed here
    db_host VARCHAR(120) NOT NULL DEFAULT 'localhost',
    db_name VARCHAR(80) NOT NULL DEFAULT '',
    db_user VARCHAR(80) NOT NULL DEFAULT '',
    db_pass_enc TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'provisioning', -- provisioning / trial / active / suspended / closed
    plan_id INT NULL,
    trial_ends DATE NULL,
    paid_until DATE NULL,
    reseller_id INT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    cron_key_enc TEXT NULL,
    notes TEXT NULL,
    close_requested_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tenant_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    plan_id INT NULL,
    months INT NOT NULL DEFAULT 1,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    ref VARCHAR(80) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending / paid / failed
    paid_at DATETIME NULL,
    reseller_id INT NULL,
    commission DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tenant (tenant_id),
    UNIQUE KEY uq_ref (ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    raised_by VARCHAR(120) NOT NULL DEFAULT '',
    subject VARCHAR(200) NOT NULL,
    body TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',  -- open / answered / closed
    reply TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO plans (code, name, price_month, price_year, max_users, max_bills_month, max_wa_month, max_locations, features, white_label, sort_order) VALUES
 ('basic',   'Basic',   299, 2990, 2,  300,  0,    1, '["billing","parties","items","payments","expenses","reports_basic"]', 0, 1),
 ('pro',     'Pro',     699, 6990, 5,  0,    1000, 2, '["billing","parties","items","payments","expenses","reports_basic","stock","purchase","whatsapp","repairs","reports","crm"]', 0, 2),
 ('premium', 'Premium', 1499, 14990, 0, 0,   0,    0, '["*"]', 1, 3);
