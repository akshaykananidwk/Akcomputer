-- v51: centralized cron scheduler - per-job execution history
CREATE TABLE IF NOT EXISTS cron_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job VARCHAR(50) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status ENUM('run','ok','fail') NOT NULL DEFAULT 'run',
  detail TEXT,
  INDEX idx_job (job, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
