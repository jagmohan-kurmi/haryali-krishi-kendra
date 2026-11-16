<?php
require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sql = "SELECT c.*, 
            COALESCE((SELECT SUM(total_amount) FROM sales WHERE customer_id = c.id), 0) AS total_purchases,
            COALESCE((SELECT SUM(amount) FROM sale_payments WHERE customer_id = c.id), 0) AS total_paid,
            COALESCE((SELECT SUM(return_amount) FROM customer_returns WHERE customer_id = c.id), 0) AS total_returns,
            (c.opening_balance + COALESCE((SELECT SUM(total_amount) FROM sales WHERE customer_id = c.id), 0) - COALESCE((SELECT SUM(amount) FROM sale_payments WHERE customer_id = c.id), 0) - COALESCE((SELECT SUM(return_amount) FROM customer_returns WHERE customer_id = c.id), 0)) AS outstanding
            FROM customers c ORDER BY c.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $customers = $stmt->fetchAll();
    sendResponse($customers);
}

if ($method === 'POST') {
    $input = getJsonInput();
    if (empty($input['name'])) {
        sendResponse(['error' => 'Customer name is required'], 400);
    }

    $id = $input['id'] ?? generateUuid();
    $farmerId = $input['farmer_id'] ?? ('RK-F-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT));
    $userId = $input['user_id'] ?? '0001';
    $name = trim($input['name']);
    $mobile = $input['mobile'] ?? null;
    $address = $input['address'] ?? null;
    $notes = $input['notes'] ?? null;
    $openingBalance = (float)($input['opening_balance'] ?? 0);
    $overduePenaltyEnabled = !empty($input['overdue_penalty_enabled']) ? 1 : 0;
    $penaltyRate = (float)($input['penalty_rate'] ?? 2.00);

    $stmt = $pdo->prepare("INSERT INTO customers (id, farmer_id, user_id, name, mobile, address, notes, opening_balance, overdue_penalty_enabled, penalty_rate, total_purchases, total_paid, outstanding, created_at) VALUES (:id, :farmer_id, :user_id, :name, :mobile, :address, :notes, :opening_balance, :overdue_penalty_enabled, :penalty_rate, 0, 0, :outstanding, NOW())");
    $stmt->execute([
        ':id' => $id,
        ':farmer_id' => $farmerId,
        ':user_id' => $userId,
        ':name' => $name,
        ':mobile' => $mobile,
        ':address' => $address,
        ':notes' => $notes,
        ':opening_balance' => $openingBalance,
        ':overdue_penalty_enabled' => $overduePenaltyEnabled,
        ':penalty_rate' => $penaltyRate,
        ':outstanding' => $openingBalance
    ]);

    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    sendResponse($stmt->fetch());
}

if ($method === 'PUT') {
    $id = $_GET['id'] ?? '';
    $input = getJsonInput();

    if (!$id) {
        sendResponse(['error' => 'Customer ID required for update'], 400);
    }

    $name = trim($input['name'] ?? '');
    $mobile = $input['mobile'] ?? null;
    $address = $input['address'] ?? null;
    $notes = $input['notes'] ?? null;
    $overduePenaltyEnabled = !empty($input['overdue_penalty_enabled']) ? 1 : 0;
    $penaltyRate = (float)($input['penalty_rate'] ?? 2.00);

    $stmt = $pdo->prepare("UPDATE customers SET name = :name, mobile = :mobile, address = :address, notes = :notes, overdue_penalty_enabled = :overdue_penalty_enabled, penalty_rate = :penalty_rate WHERE id = :id");
    $stmt->execute([
        ':name' => $name,
        ':mobile' => $mobile,
        ':address' => $address,
        ':notes' => $notes,
        ':overdue_penalty_enabled' => $overduePenaltyEnabled,
        ':penalty_rate' => $penaltyRate,
        ':id' => $id
    ]);

    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    sendResponse($stmt->fetch());
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) sendResponse(['error' => 'Customer ID required'], 400);

    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        if ($id === 'all' || $id === 'purge') {
            if ($pdo->query("SHOW TABLES LIKE 'customer_penalties'")->rowCount() > 0) {
                $pdo->exec("TRUNCATE TABLE customer_penalties;");
            }
            if ($pdo->query("SHOW TABLES LIKE 'customer_returns'")->rowCount() > 0) {
                $pdo->exec("TRUNCATE TABLE customer_return_items;");
                $pdo->exec("TRUNCATE TABLE customer_returns;");
            }
            $pdo->exec("TRUNCATE TABLE customers;");
        } else {
            $pdo->exec("DELETE FROM customer_penalties WHERE customer_id = " . $pdo->quote($id));
            $pdo->exec("DELETE FROM customer_returns WHERE customer_id = " . $pdo->quote($id));
            $pdo->exec("DELETE FROM customers WHERE id = " . $pdo->quote($id));
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        sendResponse(['success' => true]);
    } catch (Exception $e) {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        sendResponse(['error' => $e->getMessage()], 500);
    }
}

