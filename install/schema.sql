-- =====================================================
-- AK Computer - Multi-Location Billing & Shop Management
-- MySQL Schema (utf8mb4)
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Settings (key/value) ----------
CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(64) NOT NULL PRIMARY KEY,
  value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Companies / Firms (GST + non-GST, separate bill series) ----------
CREATE TABLE IF NOT EXISTS companies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  gstin VARCHAR(20) DEFAULT '',
  is_gst TINYINT(1) NOT NULL DEFAULT 0,
  address VARCHAR(255) DEFAULT '',
  phone VARCHAR(20) DEFAULT '',
  email VARCHAR(100) DEFAULT '',
  invoice_prefix VARCHAR(10) NOT NULL DEFAULT 'INV',
  terms TEXT,
  logo VARCHAR(255) DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Locations (shop / branch / godown, city-wise) ----------
CREATE TABLE IF NOT EXISTS locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(10) NOT NULL,
  city VARCHAR(60) NOT NULL,
  type ENUM('shop','branch','godown') NOT NULL DEFAULT 'shop',
  address VARCHAR(255) DEFAULT '',
  phone VARCHAR(20) DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_loc_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Roles & permissions (JSON list of "module.action") ----------
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  permissions TEXT NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Users (staff) ----------
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL,
  mobile VARCHAR(15) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role_id INT NOT NULL,
  location_id INT NOT NULL,
  permissions TEXT,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_username (username),
  KEY fk_user_role (role_id),
  KEY fk_user_loc (location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- OTP codes (login / forgot password / handover) ----------
CREATE TABLE IF NOT EXISTS otp_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purpose VARCHAR(30) NOT NULL,
  target VARCHAR(50) NOT NULL,
  code VARCHAR(10) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_otp (purpose, target, used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Credit terms dropdown ----------
CREATE TABLE IF NOT EXISTS credit_terms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  days INT NOT NULL,
  label VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Parties (customers + suppliers) ----------
CREATE TABLE IF NOT EXISTS parties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  type ENUM('customer','supplier','both','service_center') NOT NULL DEFAULT 'customer',
  mobile VARCHAR(15) DEFAULT '',
  email VARCHAR(100) DEFAULT '',
  gstin VARCHAR(20) DEFAULT '',
  address VARCHAR(255) DEFAULT '',
  city VARCHAR(60) DEFAULT '',
  credit_days INT NOT NULL DEFAULT 0,
  opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_party_type (type),
  KEY idx_party_mobile (mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Item categories ----------
CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Items ----------
CREATE TABLE IF NOT EXISTS items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  category_id INT DEFAULT NULL,
  brand VARCHAR(80) DEFAULT '',
  model VARCHAR(80) DEFAULT '',
  unit VARCHAR(20) NOT NULL DEFAULT 'PCS',
  hsn VARCHAR(20) DEFAULT '',
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  b2b_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  serial_tracked TINYINT(1) NOT NULL DEFAULT 0,
  margin_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
  item_type ENUM('product','service') NOT NULL DEFAULT 'product',
  warranty_months INT NOT NULL DEFAULT 0,
  min_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
  show_on_website TINYINT(1) NOT NULL DEFAULT 0,
  photo VARCHAR(255) DEFAULT '',
  barcode VARCHAR(64) DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_item_name (name),
  KEY idx_item_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Location-wise stock ----------
CREATE TABLE IF NOT EXISTS stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  location_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uk_stock (item_id, location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Staff-held stock (handover material) ----------
CREATE TABLE IF NOT EXISTS staff_stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  item_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uk_staff_stock (user_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Stock movement ledger (audit trail) ----------
CREATE TABLE IF NOT EXISTS stock_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  location_id INT DEFAULT NULL,
  user_id INT DEFAULT NULL,
  change_qty DECIMAL(12,2) NOT NULL,
  ref_type VARCHAR(30) NOT NULL,
  ref_id INT DEFAULT NULL,
  note VARCHAR(255) DEFAULT '',
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ledger_item (item_id),
  KEY idx_ledger_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Purchases ----------
CREATE TABLE IF NOT EXISTS purchases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL DEFAULT 1,
  bill_no VARCHAR(50) DEFAULT '',
  party_id INT NOT NULL,
  location_id INT NOT NULL,
  purchase_date DATE NOT NULL,
  credit_days INT NOT NULL DEFAULT 0,
  due_date DATE DEFAULT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('due','partial','paid') NOT NULL DEFAULT 'due',
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pur_party (party_id),
  KEY idx_pur_date (purchase_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purchase_id INT NOT NULL,
  item_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL,
  KEY idx_pi_pur (purchase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Serial numbers (warranty tracking, serial-wise) ----------
CREATE TABLE IF NOT EXISTS item_serials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  serial_no VARCHAR(100) NOT NULL,
  location_id INT DEFAULT NULL,
  user_id INT DEFAULT NULL,
  status ENUM('in_stock','with_staff','sold','claim','returned_supplier','replaced') NOT NULL DEFAULT 'in_stock',
  purchase_id INT DEFAULT NULL,
  sale_id INT DEFAULT NULL,
  warranty_months INT NOT NULL DEFAULT 0,
  warranty_expiry DATE DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_serial (item_id, serial_no),
  KEY idx_serial_no (serial_no),
  KEY idx_serial_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Sales (billing) ----------
CREATE TABLE IF NOT EXISTS sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL DEFAULT 1,
  invoice_no VARCHAR(30) DEFAULT '',
  share_token VARCHAR(40) DEFAULT '',
  party_id INT DEFAULT NULL,
  customer_name VARCHAR(120) DEFAULT '',
  customer_mobile VARCHAR(15) DEFAULT '',
  location_id INT NOT NULL,
  sale_date DATE NOT NULL,
  price_type ENUM('retail','b2b') NOT NULL DEFAULT 'retail',
  credit_days INT NOT NULL DEFAULT 0,
  due_date DATE DEFAULT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_mode VARCHAR(20) NOT NULL DEFAULT 'cash',
  status ENUM('due','partial','paid') NOT NULL DEFAULT 'paid',
  is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
  last_reminder DATE DEFAULT NULL,
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_invoice (invoice_no),
  KEY idx_sale_date (sale_date),
  KEY idx_sale_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  item_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  free_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL,
  serials TEXT,
  KEY idx_si_sale (sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Estimates / Quotations ----------
CREATE TABLE IF NOT EXISTS estimates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL DEFAULT 1,
  estimate_no VARCHAR(30) DEFAULT '',
  share_token VARCHAR(40) DEFAULT '',
  party_id INT DEFAULT NULL,
  customer_name VARCHAR(120) DEFAULT '',
  customer_mobile VARCHAR(15) DEFAULT '',
  location_id INT NOT NULL,
  estimate_date DATE NOT NULL,
  price_type ENUM('retail','b2b') NOT NULL DEFAULT 'retail',
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('open','converted','cancelled') NOT NULL DEFAULT 'open',
  converted_sale_id INT DEFAULT NULL,
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estimate_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  estimate_id INT NOT NULL,
  item_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL,
  KEY idx_ei_est (estimate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Sales returns ----------
CREATE TABLE IF NOT EXISTS sales_returns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  return_no VARCHAR(30) DEFAULT '',
  sale_id INT DEFAULT NULL,
  party_id INT DEFAULT NULL,
  customer_name VARCHAR(120) DEFAULT '',
  customer_mobile VARCHAR(15) DEFAULT '',
  location_id INT NOT NULL,
  return_date DATE NOT NULL,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  refund_mode VARCHAR(20) NOT NULL DEFAULT 'cash',
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_return_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  return_id INT NOT NULL,
  item_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  serials TEXT,
  KEY idx_sri (return_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Purchase returns ----------
CREATE TABLE IF NOT EXISTS purchase_returns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  return_no VARCHAR(30) DEFAULT '',
  purchase_id INT DEFAULT NULL,
  party_id INT NOT NULL,
  location_id INT NOT NULL,
  return_date DATE NOT NULL,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_return_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  return_id INT NOT NULL,
  item_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  serials TEXT,
  KEY idx_pri (return_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Stock handover to staff (with WhatsApp OTP) ----------
CREATE TABLE IF NOT EXISTS handovers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  handover_no VARCHAR(30) DEFAULT '',
  type ENUM('issue','return','transfer') NOT NULL DEFAULT 'issue',
  location_id INT NOT NULL,
  to_location_id INT DEFAULT NULL,
  staff_id INT DEFAULT NULL,
  status ENUM('pending','accepted','cancelled') NOT NULL DEFAULT 'pending',
  otp VARCHAR(10) DEFAULT '',
  otp_mobile VARCHAR(15) DEFAULT '',
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at DATETIME DEFAULT NULL,
  accepted_by INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS handover_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  handover_id INT NOT NULL,
  item_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  serials TEXT,
  KEY idx_hi (handover_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Field tasks / site visits ----------
CREATE TABLE IF NOT EXISTS tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_no VARCHAR(30) DEFAULT '',
  party_id INT DEFAULT NULL,
  customer_name VARCHAR(120) DEFAULT '',
  customer_mobile VARCHAR(15) DEFAULT '',
  address VARCHAR(255) DEFAULT '',
  assigned_to INT NOT NULL,
  description TEXT,
  status ENUM('assigned','started','completed','cancelled') NOT NULL DEFAULT 'assigned',
  scheduled_date DATE DEFAULT NULL,
  start_time DATETIME DEFAULT NULL,
  end_time DATETIME DEFAULT NULL,
  work_done TEXT,
  service_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
  material_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  location_id INT NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task_staff (assigned_to, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_materials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  item_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_tm (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Repair job sheets ----------
CREATE TABLE IF NOT EXISTS repairs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_no VARCHAR(30) DEFAULT '',
  party_id INT DEFAULT NULL,
  customer_name VARCHAR(120) NOT NULL,
  customer_mobile VARCHAR(15) DEFAULT '',
  device_type VARCHAR(80) DEFAULT '',
  brand_model VARCHAR(120) DEFAULT '',
  serial_no VARCHAR(100) DEFAULT '',
  accessories VARCHAR(255) DEFAULT '',
  problem TEXT,
  status ENUM('received','in_progress','outsourced','ready','delivered','returned_unrepaired') NOT NULL DEFAULT 'received',
  received_date DATE NOT NULL,
  outsource_party_id INT DEFAULT NULL,
  sent_date DATE DEFAULT NULL,
  received_back_date DATE DEFAULT NULL,
  estimate_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  outsource_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  final_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
  advance DECIMAL(12,2) NOT NULL DEFAULT 0,
  delivered_date DATE DEFAULT NULL,
  notes TEXT,
  location_id INT NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_repair_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Warranty claims (company/courier TAT tracking) ----------
CREATE TABLE IF NOT EXISTS warranty_claims (
  id INT AUTO_INCREMENT PRIMARY KEY,
  claim_no VARCHAR(30) DEFAULT '',
  item_id INT DEFAULT NULL,
  serial_no VARCHAR(100) DEFAULT '',
  party_id INT DEFAULT NULL,
  customer_name VARCHAR(120) DEFAULT '',
  customer_mobile VARCHAR(15) DEFAULT '',
  issue TEXT,
  status ENUM('received','sent','received_back','delivered','rejected') NOT NULL DEFAULT 'received',
  received_date DATE NOT NULL,
  sent_date DATE DEFAULT NULL,
  sent_courier VARCHAR(80) DEFAULT '',
  sent_tracking VARCHAR(80) DEFAULT '',
  back_date DATE DEFAULT NULL,
  back_courier VARCHAR(80) DEFAULT '',
  back_tracking VARCHAR(80) DEFAULT '',
  delivered_date DATE DEFAULT NULL,
  replacement_serial VARCHAR(100) DEFAULT '',
  notes TEXT,
  location_id INT NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_claim_status (status),
  KEY idx_claim_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Party payments (receipts / payments ledger) ----------
CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  direction ENUM('in','out') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  mode VARCHAR(20) NOT NULL DEFAULT 'cash',
  ref_type VARCHAR(20) DEFAULT NULL,
  ref_id INT DEFAULT NULL,
  pay_date DATE NOT NULL,
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pay_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Expenses ----------
CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exp_date DATE NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'General',
  amount DECIMAL(12,2) NOT NULL,
  mode VARCHAR(20) NOT NULL DEFAULT 'cash',
  notes VARCHAR(255) DEFAULT '',
  location_id INT NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_exp_date (exp_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Delivery challans ----------
CREATE TABLE IF NOT EXISTS challans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  challan_no VARCHAR(30) DEFAULT '',
  company_id INT NOT NULL DEFAULT 1,
  party_id INT DEFAULT NULL,
  customer_name VARCHAR(120) DEFAULT '',
  customer_mobile VARCHAR(15) DEFAULT '',
  address VARCHAR(255) DEFAULT '',
  location_id INT NOT NULL,
  challan_date DATE NOT NULL,
  status ENUM('open','converted','cancelled') NOT NULL DEFAULT 'open',
  converted_sale_id INT DEFAULT NULL,
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS challan_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  challan_id INT NOT NULL,
  item_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_ci (challan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Website orders ----------
CREATE TABLE IF NOT EXISTS web_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(30) DEFAULT '',
  customer_name VARCHAR(120) NOT NULL,
  mobile VARCHAR(15) NOT NULL,
  address VARCHAR(255) DEFAULT '',
  notes VARCHAR(255) DEFAULT '',
  items_json TEXT,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('new','contacted','completed','cancelled') NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Activity log ----------
CREATE TABLE IF NOT EXISTS activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  action VARCHAR(60) NOT NULL,
  details VARCHAR(500) DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_log_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
