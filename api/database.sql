-- =========================================================
--  OJ APARTMENT — Full Database Schema
--  Engine : MySQL 8+ / MariaDB 10.6+
--  Charset: utf8mb4 / utf8mb4_unicode_ci
--
--  Run once on a fresh database:
--    mysql -u root -p oj_apartment < database.sql
-- =========================================================

CREATE DATABASE IF NOT EXISTS oj_apartment
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE oj_apartment;

-- ─────────────────────────────────────────────────────────
--  USERS
-- ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120)      NOT NULL,
  email         VARCHAR(180)      NOT NULL,
  phone         VARCHAR(20)       DEFAULT NULL,
  password_hash VARCHAR(255)      NOT NULL,
  role          ENUM('admin','tenant') NOT NULL DEFAULT 'tenant',
  is_active     TINYINT(1)        NOT NULL DEFAULT 1,
  last_login    DATETIME          DEFAULT NULL,
  created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- Default admin (password: Admin@1234 — CHANGE IMMEDIATELY)
INSERT IGNORE INTO users (name, email, phone, password_hash, role)
VALUES (
  'OJ Admin',
  'admin@ojapartment.co.ke',
  '+254700000000',
  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
  'admin'
);

-- ─────────────────────────────────────────────────────────
--  ACCESS PURCHASES  (one-time paywall payments)
-- ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS access_purchases (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  plan           ENUM('basic','standard','premium') NOT NULL DEFAULT 'standard',
  tx_ref         VARCHAR(60)  DEFAULT NULL,
  mpesa_receipt  VARCHAR(20)  DEFAULT NULL,
  granted_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_access_user    (user_id),
  KEY idx_access_expires (expires_at),
  CONSTRAINT fk_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────
--  APARTMENTS
-- ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS apartments (
  id           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  unit         VARCHAR(10)    NOT NULL,
  floor        TINYINT        NOT NULL,
  type         ENUM('Studio','1-Bedroom','2-Bedroom','3-Bedroom','Penthouse') NOT NULL,
  status       ENUM('available','reserved','occupied') NOT NULL DEFAULT 'available',
  rent         DECIMAL(10,2)  NOT NULL,
  size_sqm     DECIMAL(6,1)   DEFAULT NULL,
  beds         TINYINT        NOT NULL DEFAULT 0,
  baths        TINYINT        NOT NULL DEFAULT 1,
  description  TEXT           DEFAULT NULL,
  amenities    JSON           DEFAULT NULL,
  image_url    VARCHAR(500)   DEFAULT NULL,
  created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_apt_unit (unit),
  KEY idx_apt_status (status),
  KEY idx_apt_type   (type),
  KEY idx_apt_floor  (floor)
) ENGINE=InnoDB;

-- Seed 12 apartments matching the frontend data
INSERT IGNORE INTO apartments
  (unit, floor, type, status, rent, size_sqm, beds, baths, description, amenities, image_url)
VALUES
('101',1,'Studio','available',650,38,0,1,
 'A cosy studio on the ground floor, ideal for a single professional.',
 '["Air Conditioning","Garden View","WiFi Ready","Fitted Kitchen","CCTV"]',
 'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=600&q=80'),
('202',2,'1-Bedroom','available',950,58,1,1,
 'Bright and airy 1-bedroom apartment with floor-to-ceiling windows.',
 '["Air Conditioning","City View","WiFi Ready","Balcony","Built-in Wardrobe"]',
 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=600&q=80'),
('203',2,'1-Bedroom','reserved',980,60,1,1,
 'Corner unit with dual aspects, bright living room, and a modern bathroom.',
 '["Air Conditioning","Corner Unit","WiFi Ready","Balcony","Parking"]',
 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=600&q=80'),
('304',3,'2-Bedroom','available',1350,85,2,2,
 'Spacious 2-bedroom unit perfect for couples or small families.',
 '["Air Conditioning","City View","WiFi Ready","Balcony","Parking","Laundry"]',
 'https://images.unsplash.com/photo-1493809842364-78817add7ffb?w=600&q=80'),
('305',3,'2-Bedroom','available',1400,90,2,2,
 'Modern 2-bedroom with premium finishes, island kitchen, and large balcony.',
 '["Air Conditioning","City View","WiFi Ready","Wraparound Balcony","Parking","Smart Home"]',
 'https://images.unsplash.com/photo-1484154218962-a197022b5858?w=600&q=80'),
('406',4,'3-Bedroom','available',1900,125,3,2,
 'Generous 3-bedroom family apartment with a large lounge and two private balconies.',
 '["Air Conditioning","Dual Balconies","WiFi Ready","Parking x2","Laundry","Smart Home","Storage Room"]',
 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=600&q=80'),
('407',4,'2-Bedroom','available',1450,92,2,2,
 'Contemporary 2-bed with floor-to-ceiling glazing and designer kitchen.',
 '["Air Conditioning","City View","WiFi Ready","Balcony","Parking"]',
 'https://images.unsplash.com/photo-1571055107559-3e67626fa8be?w=600&q=80'),
('508',5,'1-Bedroom','available',1050,62,1,1,
 'Elevated 1-bedroom with superb city views and a private balcony.',
 '["Air Conditioning","Panoramic View","WiFi Ready","Balcony","Parking"]',
 'https://images.unsplash.com/photo-1600607687920-4e2a09cf159d?w=600&q=80'),
('609',6,'2-Bedroom','available',1550,95,2,2,
 'High-floor 2-bed with stunning skyline views and designer finishes.',
 '["Air Conditioning","Skyline View","WiFi Ready","Balcony","Parking","Smart Home"]',
 'https://images.unsplash.com/photo-1600566753376-12c8ab7fb75b?w=600&q=80'),
('610',6,'3-Bedroom','reserved',2100,135,3,3,
 'Luxury 3-bedroom on the 6th floor with a wraparound terrace.',
 '["Air Conditioning","Wraparound Terrace","WiFi Ready","Parking x2","Smart Home","Private Lift Access"]',
 'https://images.unsplash.com/photo-1600047509807-ba8f99d2cdde?w=600&q=80'),
('711',7,'2-Bedroom','available',1700,100,2,2,
 'Premium 7th floor 2-bedroom with near-penthouse finishes and spa bathroom.',
 '["Air Conditioning","Panoramic View","WiFi Ready","Balcony","Parking","Spa Bathroom","Smart Home"]',
 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=600&q=80'),
('801',8,'Penthouse','available',4500,280,4,4,
 'Full-floor penthouse with a private rooftop terrace, cinema room, and 360° city views.',
 '["Air Conditioning","360° Views","Private Rooftop","Cinema Room","Chef Kitchen","Parking x4","Smart Home","Concierge","Wine Cellar"]',
 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=600&q=80');

-- ─────────────────────────────────────────────────────────
--  RESERVATIONS
-- ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reservations (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  apartment_id INT UNSIGNED NOT NULL,
  move_in_date DATE         NOT NULL,
  notes        TEXT         DEFAULT NULL,
  status       ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_res_user (user_id),
  KEY idx_res_apt  (apartment_id),
  KEY idx_res_status (status),
  CONSTRAINT fk_res_user FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE,
  CONSTRAINT fk_res_apt  FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────
--  M-PESA TRANSACTIONS  (STK & B2C)
-- ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mpesa_transactions (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id              INT UNSIGNED DEFAULT NULL,
  checkout_request_id  VARCHAR(60)  DEFAULT NULL,
  merchant_request_id  VARCHAR(60)  DEFAULT NULL,
  phone                VARCHAR(20)  NOT NULL,
  amount               DECIMAL(10,2) NOT NULL,
  ref                  VARCHAR(40)  DEFAULT NULL,
  type                 ENUM('stk','b2c') NOT NULL DEFAULT 'stk',
  status               ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
  mpesa_receipt        VARCHAR(20)  DEFAULT NULL,
  result_desc          VARCHAR(255) DEFAULT NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at         DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_mpesa_checkout (checkout_request_id),
  KEY idx_mpesa_user     (user_id),
  KEY idx_mpesa_status   (status)
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────
--  ENQUIRIES  (contact form)
-- ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS enquiries (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  email      VARCHAR(180) DEFAULT NULL,
  phone      VARCHAR(25)  DEFAULT NULL,
  interest   VARCHAR(100) DEFAULT NULL,
  message    TEXT         NOT NULL,
  status     ENUM('new','read','replied','closed') NOT NULL DEFAULT 'new',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_enq_status (status)
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────
--  STAFF  (payroll)
-- ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS staff (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120)  NOT NULL,
  email       VARCHAR(180)  DEFAULT NULL,
  phone       VARCHAR(20)   NOT NULL,
  department  VARCHAR(60)   DEFAULT NULL,
  role        VARCHAR(80)   DEFAULT NULL,
  salary      DECIMAL(10,2) NOT NULL,
  status      ENUM('active','inactive','pending') NOT NULL DEFAULT 'active',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_staff_status (status),
  KEY idx_staff_dept   (department)
) ENGINE=InnoDB;

-- Seed staff from the JS data
INSERT IGNORE INTO staff (name, email, phone, department, role, salary, status) VALUES
('James Mwangi',   'james@ojapt.co.ke',  '0722100001', 'Management',  'Property Manager',    85000, 'active'),
('Grace Achieng',  'grace@ojapt.co.ke',  '0733200002', 'Reception',   'Front Desk Officer',  35000, 'active'),
('Peter Ochieng',  'peter@ojapt.co.ke',  '0710300003', 'Security',    'Security Guard',      28000, 'active'),
('Faith Njeri',    'faith@ojapt.co.ke',  '0712400004', 'Cleaning',    'Housekeeper',         22000, 'active'),
('Samuel Kariuki', 'samuel@ojapt.co.ke', '0725500005', 'Maintenance', 'Plumber / Technician',40000, 'active'),
('Lucy Wambui',    'lucy@ojapt.co.ke',   '0714600006', 'Cleaning',    'Housekeeper',         22000, 'active'),
('David Otieno',   'david@ojapt.co.ke',  '0701700007', 'Security',    'Night Guard',         26000, 'active'),
('Rose Muthoni',   'rose@ojapt.co.ke',   '0798800008', 'Reception',   'Receptionist',        33000, 'inactive');

-- ─────────────────────────────────────────────────────────
--  PAYROLL TRANSACTIONS
-- ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payroll_transactions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_id      INT UNSIGNED DEFAULT NULL,
  type          ENUM('salary','withdrawal','bonus','advance','reimbursement') NOT NULL,
  amount        DECIMAL(10,2) NOT NULL,
  phone         VARCHAR(20)  DEFAULT NULL,
  reason        VARCHAR(120) DEFAULT NULL,
  mpesa_ref     VARCHAR(40)  DEFAULT NULL,
  mpesa_receipt VARCHAR(20)  DEFAULT NULL,
  month         VARCHAR(7)   DEFAULT NULL  COMMENT 'YYYY-MM',
  status        ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ptx_staff  (staff_id),
  KEY idx_ptx_month  (month),
  KEY idx_ptx_status (status),
  CONSTRAINT fk_ptx_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────
--  PAYROLL SETTINGS  (key-value store)
-- ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payroll_settings (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key   VARCHAR(60)  NOT NULL,
  setting_value VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_ps_key (setting_key)
) ENGINE=InnoDB;

-- Default settings
INSERT IGNORE INTO payroll_settings (setting_key, setting_value) VALUES
('frequency',    'Monthly'),
('payday',       '28th of every month'),
('currency',     'KES'),
('nhif',         '1'),
('nssf',         '1'),
('paye',         '1'),
('housing_levy', '0'),
('house_allow',  '1'),
('transport',    '1'),
('medical',      '0'),
('overtime',     '0'),
('custom_ded',   '0'),
('shortcode',    '522522'),
('auto_disburse','0');
