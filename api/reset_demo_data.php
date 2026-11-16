<?php
// Hostinger MySQL Demo Data Purge Script
// Truncates sales, purchases, customers, suppliers, expenses, and journal entries
// Preserves: products (inventory catalog with zero stock) & chart_of_accounts

header('Content-Type: application/json; charset=utf-8');

$cfgFile = __DIR__ . '/.ht_config.php';
if (!file_exists($cfgFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database config file missing']);
    exit;
}

$cfg = include($cfgFile);
$host = $cfg['DB_HOST'] ?? 'localhost';
$dbname = $cfg['DB_NAME'] ?? '';
$user = $cfg['DB_USER'] ?? '';
$pass = $cfg['DB_PASS'] ?? '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Connection failed: ' . $e->getMessage()]);
    exit;
}

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // Clear transactional tables
    $pdo->exec("TRUNCATE TABLE sale_payments;");
    $pdo->exec("TRUNCATE TABLE sale_items;");
    $pdo->exec("TRUNCATE TABLE sales;");

    $pdo->exec("TRUNCATE TABLE purchase_payments;");
    $pdo->exec("TRUNCATE TABLE purchase_items;");
    $pdo->exec("TRUNCATE TABLE purchases;");

    $pdo->exec("TRUNCATE TABLE expenses;");
    $pdo->exec("TRUNCATE TABLE stock_movements;");
    $pdo->exec("TRUNCATE TABLE customers;");
    $pdo->exec("TRUNCATE TABLE suppliers;");

    if ($pdo->query("SHOW TABLES LIKE 'journal_lines'")->rowCount() > 0) {
        $pdo->exec("TRUNCATE TABLE journal_lines;");
    }
    if ($pdo->query("SHOW TABLES LIKE 'journal_items'")->rowCount() > 0) {
        $pdo->exec("TRUNCATE TABLE journal_items;");
    }
    if ($pdo->query("SHOW TABLES LIKE 'journal_entries'")->rowCount() > 0) {
        $pdo->exec("TRUNCATE TABLE journal_entries;");
    }

    // Reset invoice counter
    $pdo->exec("TRUNCATE TABLE invoice_counter;");
    $pdo->exec("INSERT INTO invoice_counter (user_id, last_number) VALUES ('0001', 0);");

    if ($pdo->query("SHOW TABLES LIKE 'customer_returns'")->rowCount() > 0) {
        $pdo->exec("TRUNCATE TABLE customer_returns;");
    }
    if ($pdo->query("SHOW TABLES LIKE 'supplier_returns'")->rowCount() > 0) {
        $pdo->exec("TRUNCATE TABLE supplier_returns;");
    }
    if ($pdo->query("SHOW TABLES LIKE 'customer_penalties'")->rowCount() > 0) {
        $pdo->exec("TRUNCATE TABLE customer_penalties;");
    }

    // Preserve 164 Master Agricultural Products but reset stock to 0 (Zero Stock)
    $pdo->exec("UPDATE products SET current_stock = 0.00, opening_stock = 0.00, stock_status = 'Out of Stock';");

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // Count remaining products
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products;");
    $productCount = $stmt->fetch()['count'] ?? 0;

    echo json_encode([
        'success' => true,
        'message' => 'All demo transactions, customers, suppliers, sales, purchases, and expenses purged successfully! All products preserved with zero stock.',
        'products_preserved_count' => (int)$productCount,
        'stock_state' => 'ZERO_STOCK'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Purge error: ' . $e->getMessage()]);
}

