<?php
require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sql = "SELECT s.*, 
            COALESCE((SELECT SUM(total_amount) FROM purchases WHERE supplier_id = s.id), 0) AS total_purchases,
            COALESCE((SELECT SUM(amount) FROM purchase_payments WHERE supplier_id = s.id), 0) AS total_paid,
            COALESCE((SELECT SUM(return_amount) FROM supplier_returns WHERE supplier_id = s.id), 0) AS total_returns,
            (s.opening_payable + COALESCE((SELECT SUM(total_amount) FROM purchases WHERE supplier_id = s.id), 0) - COALESCE((SELECT SUM(amount) FROM purchase_payments WHERE supplier_id = s.id), 0) - COALESCE((SELECT SUM(return_amount) FROM supplier_returns WHERE supplier_id = s.id), 0)) AS outstanding
            FROM suppliers s ORDER BY s.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $suppliers = $stmt->fetchAll();
    sendResponse($suppliers);
}

if ($method === 'POST') {
    $input = getJsonInput();
    if (empty($input['name'])) {
        sendResponse(['error' => 'Supplier name is required'], 400);
    }

    $id = $input['id'] ?? generateUuid();
    $userId = $input['user_id'] ?? '0001';
    $name = trim($input['name']);
    $contactPerson = $input['contact_person'] ?? null;
    $mobile = !empty($input['mobile']) ? $input['mobile'] : 'N/A';
    $address = $input['address'] ?? null;
    $gstNumber = $input['gst_number'] ?? null;
    $type = $input['type'] ?? 'Company';
    $openingPayable = (float)($input['opening_payable'] ?? 0);
    $notes = $input['notes'] ?? null;

    $stmt = $pdo->prepare("INSERT INTO suppliers (id, user_id, name, contact_person, mobile, address, gst_number, type, opening_payable, total_purchases, total_paid, outstanding, notes, created_at) VALUES (:id, :user_id, :name, :contact_person, :mobile, :address, :gst_number, :type, :opening_payable, 0, 0, :outstanding, :notes, NOW())");
    $stmt->execute([
        ':id' => $id,
        ':user_id' => $userId,
        ':name' => $name,
        ':contact_person' => $contactPerson,
        ':mobile' => $mobile,
        ':address' => $address,
        ':gst_number' => $gstNumber,
        ':type' => $type,
        ':opening_payable' => $openingPayable,
        ':outstanding' => $openingPayable,
        ':notes' => $notes
    ]);

    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$id]);
    sendResponse($stmt->fetch());
}

if ($method === 'PUT') {
    $id = $_GET['id'] ?? '';
    $input = getJsonInput();
    if (!$id && !empty($input['id'])) {
        $id = $input['id'];
    }
    if (!$id) {
        sendResponse(['error' => 'Supplier ID required for update'], 400);
    }

    $fields = [];
    $params = [':id' => $id];
    $allowedCols = ['name', 'contact_person', 'mobile', 'address', 'gst_number', 'type', 'opening_payable', 'notes'];

    foreach ($allowedCols as $col) {
        if (array_key_exists($col, $input)) {
            $fields[] = "`$col` = :$col";
            $params[":$col"] = $input[$col];
        }
    }

    if (!empty($fields)) {
        $sql = "UPDATE suppliers SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$id]);
    sendResponse($stmt->fetch());
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) sendResponse(['error' => 'Supplier ID required'], 400);

    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        if ($id === 'all' || $id === 'purge') {
            if ($pdo->query("SHOW TABLES LIKE 'supplier_returns'")->rowCount() > 0) {
                $pdo->exec("TRUNCATE TABLE supplier_return_items;");
                $pdo->exec("TRUNCATE TABLE supplier_returns;");
            }
            $pdo->exec("TRUNCATE TABLE suppliers;");
        } else {
            $pdo->exec("DELETE FROM supplier_returns WHERE supplier_id = " . $pdo->quote($id));
            $pdo->exec("DELETE FROM suppliers WHERE id = " . $pdo->quote($id));
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        sendResponse(['success' => true]);
    } catch (Exception $e) {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        sendResponse(['error' => $e->getMessage()], 500);
    }
}

