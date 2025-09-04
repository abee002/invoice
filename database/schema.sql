-- database/schema.sql
-- Invoice App schema (MySQL 8+, InnoDB, utf8mb4)

-- Create database (optional)
CREATE DATABASE IF NOT EXISTS `invoice_app`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE `invoice_app`;

-- Safer defaults
SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE';

-- =============== USERS & SETTINGS ===============
CREATE TABLE IF NOT EXISTS users (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username   VARCHAR(80)  NOT NULL,
  email      VARCHAR(190) NULL,
  phone      VARCHAR(40)  NULL,
  role       VARCHAR(20)  NOT NULL DEFAULT 'user',
  onboarded  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_settings (
  user_id      INT UNSIGNED NOT NULL,
  display_name VARCHAR(190) NULL,
  address      TEXT         NULL,
  phone        VARCHAR(40)  NULL,
  logo_path    VARCHAR(255) NULL,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_settings_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============== OTP CODES ===============
CREATE TABLE IF NOT EXISTS otp_codes (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED    NOT NULL,
  channel     ENUM('email','phone') NOT NULL,
  destination VARCHAR(200)    NOT NULL,
  code        CHAR(6)         NOT NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME        NOT NULL,
  used_at     DATETIME        NULL,
  PRIMARY KEY (id),
  KEY idx_otp_user (user_id),
  KEY idx_otp_user_code (user_id, code),
  CONSTRAINT fk_otp_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============== CUSTOMERS ===============
CREATE TABLE IF NOT EXISTS customers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id      INT UNSIGNED NOT NULL,
  customer_code VARCHAR(40)  NOT NULL,
  customer_name VARCHAR(190) NOT NULL,
  address       TEXT         NULL,
  email         VARCHAR(190) NULL,
  phone         VARCHAR(40)  NULL,
  status        TINYINT(1)   NOT NULL DEFAULT 1, -- 1=active, 0=inactive
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_customers_owner (owner_id),
  UNIQUE KEY uq_customer_code_per_owner (owner_id, customer_code),
  CONSTRAINT fk_customers_owner
    FOREIGN KEY (owner_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============== PRODUCTS ===============
CREATE TABLE IF NOT EXISTS products (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id    INT UNSIGNED NOT NULL,
  sku         VARCHAR(60)  NOT NULL,
  name        VARCHAR(190) NOT NULL,
  description TEXT         NULL,
  unit        VARCHAR(20)  NOT NULL DEFAULT 'pcs',
  price       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_rate    DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  status      TINYINT(1)    NOT NULL DEFAULT 1, -- 1=active
  created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_products_owner (owner_id),
  UNIQUE KEY uq_products_sku_per_owner (owner_id, sku),
  CONSTRAINT fk_products_owner
    FOREIGN KEY (owner_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============== INVOICES ===============
CREATE TABLE IF NOT EXISTS invoices (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id           INT UNSIGNED NOT NULL,
  invoice_no         VARCHAR(60)  NOT NULL,
  customer_id        INT UNSIGNED NOT NULL,
  invoice_date       DATE         NOT NULL,
  due_date           DATE         NULL,
  discount_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_inclusive      TINYINT(1)    NOT NULL DEFAULT 0, -- 1=unit prices include tax
  notes              TEXT          NULL,
  status             ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  status_changed_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sub_total          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_total          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  grand_total        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_paid        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance_due        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_invoices_owner (owner_id),
  KEY idx_invoices_customer (customer_id),
  KEY idx_invoices_status (status),
  KEY idx_invoices_created (created_at),
  UNIQUE KEY uq_invoice_no_per_owner (owner_id, invoice_no),
  CONSTRAINT fk_invoices_owner
    FOREIGN KEY (owner_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_invoices_customer
    FOREIGN KEY (customer_id) REFERENCES customers(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============== INVOICE ITEMS ===============
CREATE TABLE IF NOT EXISTS invoice_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id    INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NULL, -- kept nullable; old items survive product deletion
  description   TEXT         NOT NULL,
  qty           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  unit_price    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_rate      DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  line_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_tax      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_total    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_items_invoice (invoice_id),
  KEY idx_items_product (product_id),
  CONSTRAINT fk_items_invoice
    FOREIGN KEY (invoice_id) REFERENCES invoices(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_items_product
    FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============== PAYMENTS ===============
CREATE TABLE IF NOT EXISTS payments (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id   INT UNSIGNED NOT NULL,
  payment_date DATE         NOT NULL,
  method       VARCHAR(80)  NOT NULL,
  reference_no VARCHAR(120) NULL,
  amount       DECIMAL(12,2) NOT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payments_invoice (invoice_id),
  KEY idx_payments_date (payment_date),
  CONSTRAINT fk_payments_invoice
    FOREIGN KEY (invoice_id) REFERENCES invoices(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============== SAMPLE DATA (optional) ===============
-- INSERT INTO users (username, email, onboarded) VALUES ('demo', 'demo@example.com', 1);

-- Done.
