-- ============================================================================
-- HOSTINGER MYSQL MIGRATION V2
-- PROJECT: Kisan Krishi Sewa Kendra ERP
-- FILE: public/api/migration_v2.sql
-- DESCRIPTION: Customer Returns, Supplier Returns, Overdue Penalties & Schema Extensions
-- ENGINE: InnoDB utf8mb4_unicode_ci
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. ADD OVERDUE PENALTY COLUMNS TO CUSTOMERS TABLE
ALTER TABLE customers 
ADD COLUMN IF NOT EXISTS overdue_penalty_enabled TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS penalty_rate DECIMAL(5,2) NOT NULL DEFAULT 2.00;

-- 2. CUSTOMER RETURNS HEADER TABLE
CREATE TABLE IF NOT EXISTS customer_returns (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  return_number VARCHAR(50) NOT NULL UNIQUE,
  original_sale_id VARCHAR(36) NOT NULL,
  customer_id VARCHAR(36) NOT NULL,
  return_date DATE NOT NULL,
  return_time VARCHAR(15) DEFAULT '12:00:00 PM',
  transaction_datetime DATETIME DEFAULT CURRENT_TIMESTAMP,
  return_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reason VARCHAR(255),
  payment_mode VARCHAR(50) DEFAULT 'KHATA_ADJUSTMENT',
  refund_status VARCHAR(50) DEFAULT 'ADJUSTED',
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (original_sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  INDEX idx_cr_user_id (user_id),
  INDEX idx_cr_sale_id (original_sale_id),
  INDEX idx_cr_customer_id (customer_id),
  INDEX idx_cr_date (return_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE customer_returns
ADD COLUMN IF NOT EXISTS return_time VARCHAR(15) DEFAULT '12:00:00 PM',
ADD COLUMN IF NOT EXISTS transaction_datetime DATETIME DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN IF NOT EXISTS payment_mode VARCHAR(50) DEFAULT 'KHATA_ADJUSTMENT',
ADD COLUMN IF NOT EXISTS refund_status VARCHAR(50) DEFAULT 'ADJUSTED',
ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 3. CUSTOMER RETURN ITEMS TABLE
CREATE TABLE IF NOT EXISTS customer_return_items (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  return_id VARCHAR(36) NOT NULL,
  product_id VARCHAR(36) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit VARCHAR(50) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  batch_number VARCHAR(100) DEFAULT NULL,
  expiry_date DATE DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (return_id) REFERENCES customer_returns(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  INDEX idx_cri_return_id (return_id),
  INDEX idx_cri_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE customer_return_items
ADD COLUMN IF NOT EXISTS batch_number VARCHAR(100) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS expiry_date DATE DEFAULT NULL;

-- 4. SUPPLIER RETURNS HEADER TABLE
CREATE TABLE IF NOT EXISTS supplier_returns (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  return_number VARCHAR(50) NOT NULL UNIQUE,
  original_purchase_id VARCHAR(36) NOT NULL,
  supplier_id VARCHAR(36) NOT NULL,
  return_date DATE NOT NULL,
  return_time VARCHAR(15) DEFAULT '12:00:00 PM',
  transaction_datetime DATETIME DEFAULT CURRENT_TIMESTAMP,
  return_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reason VARCHAR(255),
  payment_mode VARCHAR(50) DEFAULT 'PAYABLE_ADJUSTMENT',
  refund_status VARCHAR(50) DEFAULT 'ADJUSTED',
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (original_purchase_id) REFERENCES purchases(id) ON DELETE RESTRICT,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
  INDEX idx_sr_user_id (user_id),
  INDEX idx_sr_purchase_id (original_purchase_id),
  INDEX idx_sr_supplier_id (supplier_id),
  INDEX idx_sr_date (return_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE supplier_returns
ADD COLUMN IF NOT EXISTS return_time VARCHAR(15) DEFAULT '12:00:00 PM',
ADD COLUMN IF NOT EXISTS transaction_datetime DATETIME DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN IF NOT EXISTS payment_mode VARCHAR(50) DEFAULT 'PAYABLE_ADJUSTMENT',
ADD COLUMN IF NOT EXISTS refund_status VARCHAR(50) DEFAULT 'ADJUSTED',
ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 5. SUPPLIER RETURN ITEMS TABLE
CREATE TABLE IF NOT EXISTS supplier_return_items (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  return_id VARCHAR(36) NOT NULL,
  product_id VARCHAR(36) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit VARCHAR(50) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  batch_number VARCHAR(100) DEFAULT NULL,
  expiry_date DATE DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (return_id) REFERENCES supplier_returns(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  INDEX idx_sri_return_id (return_id),
  INDEX idx_sri_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE supplier_return_items
ADD COLUMN IF NOT EXISTS batch_number VARCHAR(100) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS expiry_date DATE DEFAULT NULL;

-- 6. CUSTOMER OVERDUE PENALTIES TABLE
CREATE TABLE IF NOT EXISTS customer_penalties (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  customer_id VARCHAR(36) NOT NULL,
  source_sale_id VARCHAR(36) NOT NULL,
  penalty_date DATE NOT NULL,
  calculation_date DATE NOT NULL,
  base_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  rate DECIMAL(5,2) NOT NULL DEFAULT 2.00,
  penalty_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(50) NOT NULL DEFAULT 'Applied',
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  FOREIGN KEY (source_sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_customer_sale_penalty (customer_id, source_sale_id, penalty_date),
  INDEX idx_cp_customer_id (customer_id),
  INDEX idx_cp_sale_id (source_sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. ADD INVOICE GST DISPLAY OPTION TO SALES TABLE
ALTER TABLE sales 
ADD COLUMN IF NOT EXISTS show_gst TINYINT(1) NOT NULL DEFAULT 1;

SET FOREIGN_KEY_CHECKS = 1;
