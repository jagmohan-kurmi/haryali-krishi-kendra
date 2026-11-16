<?php
// Hostinger MySQL Migration v2 Execution Script
header('Content-Type: application/json; charset=utf-8');

if (file_exists(__DIR__ . '/.ht_config.php')) {
    $cfg = include(__DIR__ . '/.ht_config.php');
} else {
    echo json_encode(['success' => false, 'error' => 'Config missing']);
    exit;
}

try {
    $pdo = new PDO("mysql:host={$cfg['DB_HOST']};dbname={$cfg['DB_NAME']};charset=utf8mb4", $cfg['DB_USER'], $cfg['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // Add columns to customers table if not exists
    try {
        $pdo->exec("ALTER TABLE customers ADD COLUMN overdue_penalty_enabled TINYINT(1) NOT NULL DEFAULT 0;");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE customers ADD COLUMN penalty_rate DECIMAL(5,2) NOT NULL DEFAULT 2.00;");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00;");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN hsn_code VARCHAR(50) DEFAULT NULL;");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE purchase_items ADD COLUMN hsn_code VARCHAR(50) DEFAULT NULL;");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE purchase_items ADD COLUMN gst_rate DECIMAL(5,2) DEFAULT NULL;");
    } catch (Exception $e) {}

    // Customer Returns Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_returns (
      id VARCHAR(36) PRIMARY KEY,
      user_id VARCHAR(36) NOT NULL DEFAULT '0001',
      return_number VARCHAR(50) NOT NULL UNIQUE,
      original_sale_id VARCHAR(36) NOT NULL,
      customer_id VARCHAR(36) NOT NULL,
      return_date DATE NOT NULL,
      return_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      reason VARCHAR(255),
      notes TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Customer Return Items Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_return_items (
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
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Supplier Returns Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_returns (
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
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Supplier Return Items Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_return_items (
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
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Execute Column Additions safely (Try/Catch per query)
    $alterQueries = [
      "ALTER TABLE customer_returns ADD COLUMN return_time VARCHAR(15) DEFAULT '12:00:00 PM'",
      "ALTER TABLE customer_returns ADD COLUMN transaction_datetime DATETIME DEFAULT CURRENT_TIMESTAMP",
      "ALTER TABLE customer_returns ADD COLUMN payment_mode VARCHAR(50) DEFAULT 'KHATA_ADJUSTMENT'",
      "ALTER TABLE customer_returns ADD COLUMN refund_status VARCHAR(50) DEFAULT 'ADJUSTED'",
      "ALTER TABLE customer_return_items ADD COLUMN batch_number VARCHAR(100) DEFAULT NULL",
      "ALTER TABLE customer_return_items ADD COLUMN expiry_date DATE DEFAULT NULL",
      "ALTER TABLE supplier_returns ADD COLUMN return_time VARCHAR(15) DEFAULT '12:00:00 PM'",
      "ALTER TABLE supplier_returns ADD COLUMN transaction_datetime DATETIME DEFAULT CURRENT_TIMESTAMP",
      "ALTER TABLE supplier_returns ADD COLUMN payment_mode VARCHAR(50) DEFAULT 'PAYABLE_ADJUSTMENT'",
      "ALTER TABLE supplier_returns ADD COLUMN refund_status VARCHAR(50) DEFAULT 'ADJUSTED'",
      "ALTER TABLE supplier_return_items ADD COLUMN batch_number VARCHAR(100) DEFAULT NULL",
      "ALTER TABLE supplier_return_items ADD COLUMN expiry_date DATE DEFAULT NULL",
    ];
    foreach ($alterQueries as $aq) {
      try { $pdo->exec($aq); } catch (Exception $e) {}
    }

    // Customer Penalties Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_penalties (
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
      UNIQUE KEY uq_customer_sale_penalty (customer_id, source_sale_id, penalty_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Seed Sample Returns & Penalties
    try {
        $pdo->exec("INSERT IGNORE INTO customer_returns (id, user_id, return_number, original_sale_id, customer_id, return_date, return_amount, reason, notes, created_at) VALUES ('cr-1', '0001', 'CUST-RET-20260815-1001', 'sale-1', 'c1', '2026-08-15', 600.00, 'पैकिंग डैमेज / बैग फटा हुआ पाया गया', '2 बैग इफ्को नीम कोटेड यूरिया वापस लिया गया', '2026-08-15');");
        $pdo->exec("INSERT IGNORE INTO customer_returns (id, user_id, return_number, original_sale_id, customer_id, return_date, return_amount, reason, notes, created_at) VALUES ('cr-2', '0001', 'CUST-RET-20260818-1002', 'sale-3', 'c3', '2026-08-18', 1450.00, 'गलत किस्म का चयन', '1 बैग इफ्को DAP ग्राहक द्वारा वापसी', '2026-08-18');");

        $pdo->exec("INSERT IGNORE INTO customer_return_items (id, user_id, return_id, product_id, quantity, unit, unit_price, total, created_at) VALUES ('cri-1', '0001', 'cr-1', 'p1', 2.00, 'Bag', 300.00, 600.00, '2026-08-15');");
        $pdo->exec("INSERT IGNORE INTO customer_return_items (id, user_id, return_id, product_id, quantity, unit, unit_price, total, created_at) VALUES ('cri-2', '0001', 'cr-2', 'p2', 1.00, 'Bag', 1450.00, 1450.00, '2026-08-18');");

        $pdo->exec("INSERT IGNORE INTO supplier_returns (id, user_id, return_number, original_purchase_id, supplier_id, return_date, return_amount, reason, notes, created_at) VALUES ('sr-1', '0001', 'SUP-RET-20260814-2001', 'pur-2', 's2', '2026-08-14', 2800.00, 'बोतल लीक होने के कारण वापसी', '2 शीशी कोराजन सप्लायर बायर् को वापस की गई', '2026-08-14');");

        $pdo->exec("INSERT IGNORE INTO supplier_return_items (id, user_id, return_id, product_id, quantity, unit, unit_price, total, created_at) VALUES ('sri-1', '0001', 'sr-1', 'p3', 2.00, 'Bottle', 1400.00, 2800.00, '2026-08-14');");

        $pdo->exec("INSERT IGNORE INTO customer_penalties (id, user_id, customer_id, source_sale_id, penalty_date, calculation_date, base_amount, rate, penalty_amount, created_at) VALUES ('cp-1', '0001', 'c2', 'sale-2', '2026-08-20', '2026-08-20', 3500.00, 2.00, 70.00, '2026-08-20');");
    } catch (Exception $e) {}

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo json_encode([
        'success' => true,
        'message' => 'Migration V2 tables & columns applied successfully on Hostinger MySQL!'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
