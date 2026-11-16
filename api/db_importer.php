<?php
// Secure Hostinger MySQL Schema & Seed Importer + Reconciliation Tool
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);

$host = $input['db_host'] ?? 'localhost';
$dbname = $input['db_name'] ?? '';
$user = $input['db_user'] ?? '';
$pass = $input['db_pass'] ?? '';

if (empty($dbname) || empty($user)) {
    // Try to auto-detect if config file exists
    if (file_exists(__DIR__ . '/.ht_config.php')) {
        $cfg = include(__DIR__ . '/.ht_config.php');
        if (is_array($cfg)) {
            $host = $cfg['DB_HOST'] ?? $host;
            $dbname = $cfg['DB_NAME'] ?? $dbname;
            $user = $cfg['DB_USER'] ?? $user;
            $pass = $cfg['DB_PASS'] ?? $pass;
        }
    }
}

if (empty($dbname) || empty($user)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'MySQL Database Credentials Required',
        'message' => 'Please provide db_name, db_user, db_pass in request or configure hPanel MySQL database.'
    ]);
    exit;
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database Connection Failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

$schemaFile = __DIR__ . '/schema_hostinger_mysql.sql';
$migrationFile = __DIR__ . '/migration_v2.sql';
$seedFile = __DIR__ . '/seed_hostinger_mysql.sql';

$schemaImported = false;
$migrationImported = false;
$seedImported = false;

if (file_exists($schemaFile)) {
    $schemaSql = file_get_contents($schemaFile);
    $pdo->exec($schemaSql);
    $schemaImported = true;
}

if (file_exists($migrationFile)) {
    $migrationSql = file_get_contents($migrationFile);
    $pdo->exec($migrationSql);
    $migrationImported = true;
}

if (file_exists($seedFile)) {
    $seedSql = file_get_contents($seedFile);
    $pdo->exec($seedSql);
    $seedImported = true;
}

// Perform Read-Only Reconciliation Queries
$counts = [];
$tables = ['customers', 'suppliers', 'products', 'stock_movements', 'sales', 'sale_items', 'sale_payments', 'purchases', 'purchase_items', 'purchase_payments', 'customer_returns', 'customer_return_items', 'supplier_returns', 'supplier_return_items', 'customer_penalties', 'expenses', 'invoice_counter', 'chart_of_accounts', 'journal_entries', 'journal_lines'];

foreach ($tables as $tbl) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) AS total FROM `$tbl`");
        $counts[$tbl] = (int)$stmt->fetch()['total'];
    } catch (Exception $e) {
        $counts[$tbl] = 0;
    }
}

// Financial Totals
$salesTotal = (float)$pdo->query("SELECT SUM(total_amount) AS total FROM sales")->fetch()['total'];
$purchasesTotal = (float)$pdo->query("SELECT SUM(total_amount) AS total FROM purchases")->fetch()['total'];
$expensesTotal = (float)$pdo->query("SELECT SUM(amount) AS total FROM expenses")->fetch()['total'];
$inventoryVal = (float)$pdo->query("SELECT SUM(current_stock * purchase_price) AS total FROM products")->fetch()['total'];
$receivablesTotal = (float)$pdo->query("SELECT SUM(outstanding) AS total FROM customers")->fetch()['total'];
$payablesTotal = (float)$pdo->query("SELECT SUM(outstanding) AS total FROM suppliers")->fetch()['total'];
$journalDebits = (float)$pdo->query("SELECT SUM(debit) AS total FROM journal_lines")->fetch()['total'];
$journalCredits = (float)$pdo->query("SELECT SUM(credit) AS total FROM journal_lines")->fetch()['total'];

$reconciliation = [
    'sales_match' => ($salesTotal === 50400.0),
    'purchases_match' => ($purchasesTotal === 272000.0),
    'expenses_match' => ($expensesTotal === 32800.0),
    'inventory_match' => ($inventoryVal === 407800.0),
    'receivables_match' => ($receivablesTotal === 20150.0),
    'payables_match' => ($payablesTotal === 88100.0),
    'trial_balance_balanced' => ($journalDebits === $journalCredits)
];

$allMatched = !in_array(false, array_values($reconciliation), true);

echo json_encode([
    'success' => true,
    'schema_imported' => $schemaImported,
    'seed_imported' => $seedImported,
    'row_counts' => $counts,
    'total_rows' => array_sum($counts),
    'financials' => [
        'sales_total' => $salesTotal,
        'purchases_total' => $purchasesTotal,
        'expenses_total' => $expensesTotal,
        'inventory_valuation' => $inventoryVal,
        'receivables_total' => $receivablesTotal,
        'payables_total' => $payablesTotal,
        'journal_debits' => $journalDebits,
        'journal_credits' => $journalCredits
    ],
    'reconciliation' => $reconciliation,
    'phase_4_status' => $allMatched ? 'PHASE_4_VERIFIED_MATCH' : 'PHASE_4_MISMATCH_DETECTED'
]);
