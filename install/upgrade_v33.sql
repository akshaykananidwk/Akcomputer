-- E-commerce storefront upgrade:
-- daily unique visitor tracking for the public site (one row per person/day;
-- person = hash of ip+browser, no personal data stored)
CREATE TABLE IF NOT EXISTS site_visits (
  visit_date DATE NOT NULL,
  visitor_hash VARCHAR(40) NOT NULL,
  views INT NOT NULL DEFAULT 1,
  PRIMARY KEY (visit_date, visitor_hash)
);

-- dealers can now register themselves from the website; such accounts start
-- inactive (discount 0) until the shop approves them in web_customers.php
ALTER TABLE web_accounts ADD COLUMN self_registered TINYINT(1) NOT NULL DEFAULT 0;
