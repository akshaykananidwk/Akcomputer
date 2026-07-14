-- Upgrade v17 -> v18: Service & CRM foundation - technician assignment +
-- spare-parts consumption on repair jobs, a configurable service checklist,
-- before/after photos, a shareable digital service report, leads, follow-ups
-- and a complaint/ticket system. Nothing here changes existing repair/task
-- behaviour - it's all additive columns/tables layered on top.

ALTER TABLE repairs ADD COLUMN assigned_to INT NULL AFTER outsource_party_id;
ALTER TABLE repairs ADD COLUMN report_token VARCHAR(40) NULL;
ALTER TABLE repairs ADD KEY idx_repair_assigned (assigned_to);
ALTER TABLE repairs ADD UNIQUE KEY uk_repair_token (report_token);

CREATE TABLE IF NOT EXISTS repair_materials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repair_id INT NOT NULL,
  item_id INT NOT NULL,
  qty DECIMAL(10,2) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rm_repair (repair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_checklist_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(150) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS repair_checklist (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repair_id INT NOT NULL,
  checklist_item_id INT NOT NULL,
  result ENUM('pass','fail','na') NOT NULL DEFAULT 'na',
  notes VARCHAR(255) DEFAULT '',
  UNIQUE KEY uk_rc (repair_id, checklist_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS repair_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repair_id INT NOT NULL,
  type ENUM('before','after') NOT NULL DEFAULT 'before',
  path VARCHAR(255) NOT NULL,
  uploaded_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rp_repair (repair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_no VARCHAR(30) DEFAULT '',
  name VARCHAR(120) NOT NULL,
  mobile VARCHAR(20) DEFAULT '',
  email VARCHAR(120) DEFAULT '',
  source VARCHAR(60) DEFAULT '',
  interest VARCHAR(255) DEFAULT '',
  status ENUM('new','contacted','quoted','won','lost') NOT NULL DEFAULT 'new',
  assigned_to INT NULL,
  party_id INT NULL,
  notes TEXT,
  location_id INT NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lead_status (status),
  KEY idx_lead_assigned (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS follow_ups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref_type VARCHAR(20) DEFAULT '',
  ref_id INT NULL,
  party_id INT NULL,
  title VARCHAR(200) NOT NULL,
  due_date DATE NOT NULL,
  status ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending',
  assigned_to INT NULL,
  notes VARCHAR(500) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  KEY idx_fu_due (due_date, status),
  KEY idx_fu_ref (ref_type, ref_id),
  KEY idx_fu_assigned (assigned_to),
  KEY idx_fu_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_no VARCHAR(30) DEFAULT '',
  party_id INT NULL,
  customer_name VARCHAR(120) DEFAULT '',
  customer_mobile VARCHAR(20) DEFAULT '',
  subject VARCHAR(200) NOT NULL,
  description TEXT,
  priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  assigned_to INT NULL,
  repair_id INT NULL,
  resolution TEXT,
  resolved_at DATETIME NULL,
  location_id INT NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tk_status (status),
  KEY idx_tk_party (party_id),
  KEY idx_tk_assigned (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  comment TEXT NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tc_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO service_checklist_items (id, label, sort_order) VALUES
(1, 'Power on / boot test', 10),
(2, 'Physical damage inspection', 20),
(3, 'Diagnosis confirmed with customer', 30),
(4, 'Data backup taken (if applicable)', 40),
(5, 'Repair / replacement completed', 50),
(6, 'Cleaning done', 60),
(7, 'Final functionality test', 70),
(8, 'Customer demo / handover explained', 80);
