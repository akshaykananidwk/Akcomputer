-- Upgrade v20 -> v21: Reports & dashboards - custom report builder (saved,
-- named report configs against an allowlisted set of data sources/columns -
-- never raw SQL) and scheduled reports (WhatsApp summary on a recurring
-- schedule, processed by the existing cron.php entry point). Everything
-- else in this phase (trend graphs, branch/staff comparison, product-wise
-- profit) is new report tabs / dashboard widgets with no schema needed.

CREATE TABLE IF NOT EXISTS custom_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  source VARCHAR(30) NOT NULL,
  columns_json TEXT,
  group_by VARCHAR(30) DEFAULT '',
  sort_by VARCHAR(30) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  report_key VARCHAR(30) NOT NULL,
  location_id INT NULL,
  frequency ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'daily',
  day_of_week TINYINT NULL,
  day_of_month TINYINT NULL,
  recipient_mobile VARCHAR(20) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_run_at DATETIME NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rs_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
