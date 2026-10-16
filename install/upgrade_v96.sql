-- v96: attendance rules, hourly pay with a monthly raise, emergency leave
-- with a certificate, penalties, and bike km for the petrol allowance.
-- Adds only. Penalty rules stay OFF until the owner saves them in
-- Team -> Attendance rules, and apply only from that day on.
ALTER TABLE users ADD COLUMN pay_type VARCHAR(8) NOT NULL DEFAULT 'monthly';
ALTER TABLE users ADD COLUMN hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN raise_amount DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN raise_from CHAR(7) NULL;
ALTER TABLE users ADD COLUMN raise_max DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN bike_allowed TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN petrol_rate DECIMAL(6,2) NULL;
ALTER TABLE leave_requests ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'planned';
ALTER TABLE leave_requests ADD COLUMN certificate VARCHAR(200) NULL;
ALTER TABLE payslips ADD COLUMN pay_type VARCHAR(8) NOT NULL DEFAULT 'monthly';
ALTER TABLE payslips ADD COLUMN hours DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE payslips ADD COLUMN rate DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE payslips ADD COLUMN penalty_hours DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE payslips ADD COLUMN penalty_amt DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE payslips ADD COLUMN km DECIMAL(9,2) NOT NULL DEFAULT 0;
ALTER TABLE payslips ADD COLUMN petrol DECIMAL(12,2) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS hr_penalties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  pen_date DATE NOT NULL,
  kind VARCHAR(8) NOT NULL DEFAULT 'manual',
  hours DECIMAL(6,2) NOT NULL DEFAULT 0,
  reason VARCHAR(200) NOT NULL DEFAULT '',
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pen_user (user_id, pen_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bike_trips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  km DECIMAL(8,2) NOT NULL DEFAULT 0,
  adj_km DECIMAL(8,2) NULL,
  adj_note VARCHAR(200) NOT NULL DEFAULT '',
  status VARCHAR(8) NOT NULL DEFAULT 'running',
  note VARCHAR(200) NOT NULL DEFAULT '',
  KEY idx_trip_user (user_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bike_points (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  at DATETIME NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  acc SMALLINT NOT NULL DEFAULT 0,
  KEY idx_pt_trip (trip_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
