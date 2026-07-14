-- Upgrade v23 -> v24: AI features - purchase recommendations (demand-driven,
-- on top of the existing static min_stock Low Stock report), auto expense
-- categorization (keyword-based suggestion, the category field itself
-- stays the existing fixed dropdown), and an AI Insights dashboard card.
-- No schema change needed for forecasting/insights (both compute purely
-- from existing sales/expenses/items data) - only the keyword lookup table
-- for expense category suggestions is new.

CREATE TABLE IF NOT EXISTS expense_category_keywords (
  id INT AUTO_INCREMENT PRIMARY KEY,
  keyword VARCHAR(50) NOT NULL,
  category VARCHAR(80) NOT NULL,
  UNIQUE KEY uk_eck_keyword (keyword)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO expense_category_keywords (keyword, category) VALUES
('rent', 'Rent'),
('salary', 'Salary'), ('wage', 'Salary'), ('staff pay', 'Salary'),
('electricity', 'Electricity'), ('power bill', 'Electricity'), ('bijli', 'Electricity'),
('internet', 'Internet'), ('wifi', 'Internet'), ('broadband', 'Internet'),
('petrol', 'Transport'), ('diesel', 'Transport'), ('fuel', 'Transport'), ('travel', 'Transport'), ('taxi', 'Transport'), ('auto fare', 'Transport'),
('tea', 'Tea/Food'), ('chai', 'Tea/Food'), ('lunch', 'Tea/Food'), ('food', 'Tea/Food'), ('snacks', 'Tea/Food'), ('coffee', 'Tea/Food'),
('paper', 'Stationery'), ('pen', 'Stationery'), ('stationery', 'Stationery'), ('printer ink', 'Stationery'),
('repair', 'Repair/Maintenance'), ('maintenance', 'Repair/Maintenance'), ('ac service', 'Repair/Maintenance'),
('advertisement', 'Marketing'), ('marketing', 'Marketing'), ('promotion', 'Marketing'), ('facebook ads', 'Marketing'), ('google ads', 'Marketing');
