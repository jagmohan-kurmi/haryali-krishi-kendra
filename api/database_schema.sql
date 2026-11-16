-- ============================================================================
-- HOSTINGER MYSQL / MARIADB DATABASE SCHEMA
-- PROJECT: Kisan Krishi Sewa Kendra ERP
-- FILE: hostinger/schema_hostinger_mysql.sql
-- DESCRIPTION: Production-ready DDL for Hostinger MySQL/MariaDB environment
-- ENGINE: InnoDB utf8mb4_unicode_ci
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. FARMER CUSTOMERS TABLE
CREATE TABLE IF NOT EXISTS customers (
  id VARCHAR(36) PRIMARY KEY,
  farmer_id VARCHAR(50) NOT NULL,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  name VARCHAR(255) NOT NULL,
  mobile VARCHAR(20) NOT NULL,
  address TEXT,
  notes TEXT,
  opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_purchases DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  outstanding DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cust_user_id (user_id),
  INDEX idx_cust_farmer_id (farmer_id),
  INDEX idx_cust_mobile (mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. SUPPLIERS TABLE
CREATE TABLE IF NOT EXISTS suppliers (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  name VARCHAR(255) NOT NULL,
  contact_person VARCHAR(255),
  mobile VARCHAR(20) NOT NULL,
  address TEXT,
  gst_number VARCHAR(20),
  type VARCHAR(50) NOT NULL DEFAULT 'Company',
  opening_payable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_purchases DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  outstanding DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sup_user_id (user_id),
  INDEX idx_sup_gst (gst_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. PRODUCTS & INVENTORY TABLE
CREATE TABLE IF NOT EXISTS products (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  name VARCHAR(255) NOT NULL,
  sku VARCHAR(100) NOT NULL,
  category VARCHAR(100) NOT NULL,
  brand VARCHAR(100),
  company VARCHAR(100),
  unit VARCHAR(50) NOT NULL,
  purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  opening_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  current_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  minimum_stock DECIMAL(12,2) NOT NULL DEFAULT 10.00,
  batch_number VARCHAR(100),
  mfd_date DATE,
  expiry_date DATE,
  license_number VARCHAR(100),
  description TEXT,
  stock_status VARCHAR(50) NOT NULL DEFAULT 'Healthy',
  expiry_status VARCHAR(50) NOT NULL DEFAULT 'Valid',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_prod_user_id (user_id),
  INDEX idx_prod_sku (sku),
  INDEX idx_prod_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. STOCK MOVEMENTS TABLE
CREATE TABLE IF NOT EXISTS stock_movements (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  product_id VARCHAR(36) NOT NULL,
  type VARCHAR(50) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  balance_after DECIMAL(12,2) NOT NULL,
  reference_id VARCHAR(36),
  reference_type VARCHAR(50),
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  INDEX idx_sm_product_id (product_id),
  INDEX idx_sm_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. SALES INVOICES TABLE
CREATE TABLE IF NOT EXISTS sales (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  invoice_number VARCHAR(50) NOT NULL UNIQUE,
  customer_id VARCHAR(36) NOT NULL,
  sale_date DATE NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash',
  due_date DATE,
  notes TEXT,
  proof_image TEXT,
  status VARCHAR(50) NOT NULL DEFAULT 'Unpaid',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  INDEX idx_sales_user_id (user_id),
  INDEX idx_sales_customer_id (customer_id),
  INDEX idx_sales_date (sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. SALE ITEMS TABLE
CREATE TABLE IF NOT EXISTS sale_items (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  sale_id VARCHAR(36) NOT NULL,
  product_id VARCHAR(36) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit VARCHAR(50) NOT NULL,
  selling_price DECIMAL(12,2) NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  INDEX idx_si_sale_id (sale_id),
  INDEX idx_si_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. SALE PAYMENTS TABLE
CREATE TABLE IF NOT EXISTS sale_payments (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  sale_id VARCHAR(36) NOT NULL,
  customer_id VARCHAR(36) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_method VARCHAR(50) NOT NULL,
  payment_date DATE NOT NULL,
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  INDEX idx_sp_sale_id (sale_id),
  INDEX idx_sp_customer_id (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. PURCHASES TABLE
CREATE TABLE IF NOT EXISTS purchases (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  invoice_number VARCHAR(50) NOT NULL UNIQUE,
  supplier_id VARCHAR(36) NOT NULL,
  purchase_date DATE NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_method VARCHAR(50) NOT NULL DEFAULT 'Bank',
  due_date DATE,
  notes TEXT,
  status VARCHAR(50) NOT NULL DEFAULT 'Unpaid',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
  INDEX idx_pur_user_id (user_id),
  INDEX idx_pur_supplier_id (supplier_id),
  INDEX idx_pur_date (purchase_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. PURCHASE ITEMS TABLE
CREATE TABLE IF NOT EXISTS purchase_items (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  purchase_id VARCHAR(36) NOT NULL,
  product_id VARCHAR(36) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit VARCHAR(50) NOT NULL,
  purchase_rate DECIMAL(12,2) NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  INDEX idx_pi_purchase_id (purchase_id),
  INDEX idx_pi_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. PURCHASE PAYMENTS TABLE
CREATE TABLE IF NOT EXISTS purchase_payments (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  purchase_id VARCHAR(36) NOT NULL,
  supplier_id VARCHAR(36) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_method VARCHAR(50) NOT NULL,
  payment_date DATE NOT NULL,
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
  INDEX idx_pp_purchase_id (purchase_id),
  INDEX idx_pp_supplier_id (supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. EXPENSES TABLE
CREATE TABLE IF NOT EXISTS expenses (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  category VARCHAR(100) NOT NULL,
  title VARCHAR(255) NOT NULL,
  staff_name VARCHAR(255),
  salary_month VARCHAR(50),
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL,
  payment_method VARCHAR(50) NOT NULL,
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exp_user_id (user_id),
  INDEX idx_exp_category (category),
  INDEX idx_exp_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. INVOICE COUNTER TABLE
CREATE TABLE IF NOT EXISTS invoice_counter (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id VARCHAR(36) NOT NULL UNIQUE,
  last_number INT NOT NULL DEFAULT 10,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. CHART OF ACCOUNTS TABLE
CREATE TABLE IF NOT EXISTS chart_of_accounts (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  account_code VARCHAR(20) NOT NULL,
  account_name VARCHAR(255) NOT NULL,
  category VARCHAR(50) NOT NULL CHECK (category IN ('ASSET', 'LIABILITY', 'EQUITY', 'INCOME', 'EXPENSE')),
  opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  is_system TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_account_code (user_id, account_code),
  INDEX idx_coa_user_id (user_id),
  INDEX idx_coa_code (account_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. JOURNAL ENTRIES TABLE
CREATE TABLE IF NOT EXISTS journal_entries (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  entry_number VARCHAR(50) NOT NULL,
  entry_date DATE NOT NULL,
  source_module VARCHAR(50) NOT NULL,
  source_id VARCHAR(36),
  reference_number VARCHAR(50),
  narration TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_journal_source (user_id, source_module, source_id),
  INDEX idx_je_user_id (user_id),
  INDEX idx_je_date (entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. JOURNAL LINES TABLE
CREATE TABLE IF NOT EXISTS journal_lines (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL DEFAULT '0001',
  journal_id VARCHAR(36) NOT NULL,
  account_id VARCHAR(36) NOT NULL,
  debit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  credit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  memo TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (journal_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id) ON DELETE RESTRICT,
  INDEX idx_jl_journal_id (journal_id),
  INDEX idx_jl_account_id (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
