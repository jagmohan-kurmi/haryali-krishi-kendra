<?php
require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $customerId = $_GET['customer_id'] ?? '';
    if ($customerId) {
        $stmt = $pdo->prepare("SELECT * FROM customer_penalties WHERE customer_id = ? ORDER BY penalty_date DESC");
        $stmt->execute([$customerId]);
    } else {
        $stmt = $pdo->prepare("SELECT p.*, c.name as customer_name FROM customer_penalties p JOIN customers c ON p.customer_id = c.id ORDER BY p.penalty_date DESC");
        $stmt->execute();
    }
    sendResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    $input = getJsonInput();
    $customerId = $input['customer_id'] ?? '';
    $sourceSaleId = $input['source_sale_id'] ?? '';
    $penaltyDate = trim($input['penalty_date'] ?? date('Y-m-d'));
    $baseAmount = (float)($input['base_amount'] ?? 0);
    $rate = (float)($input['rate'] ?? 2.00);
    $penaltyAmount = round(($baseAmount * $rate) / 100, 2);
    $notes = trim($input['notes'] ?? 'Overdue penalty (60+ days)');

    if (!$customerId || !$sourceSaleId || $baseAmount <= 0) {
        sendResponse(['error' => 'Customer ID, Source Sale ID, and base amount are required.'], 400);
    }

    if (empty($penaltyDate) || $penaltyDate === '0000-00-00') {
        sendResponse(['error' => 'HARD ACCOUNTING REQUIREMENT: Penalty transaction date is mandatory.'], 400);
    }

    // Duplicate penalty prevention check
    $chk = $pdo->prepare("SELECT id FROM customer_penalties WHERE customer_id = ? AND source_sale_id = ? AND penalty_date = ?");
    $chk->execute([$customerId, $sourceSaleId, $penaltyDate]);
    if ($chk->fetch()) {
        sendResponse(['error' => 'Duplicate Penalty Protection: A penalty has already been calculated for this sale and date.'], 400);
    }

    $pdo->beginTransaction();
    try {
        $id = generateUuid();
        $calcDate = date('Y-m-d');

        $stmt = $pdo->prepare("INSERT INTO customer_penalties (id, user_id, customer_id, source_sale_id, penalty_date, calculation_date, base_amount, rate, penalty_amount, status, notes, created_at) VALUES (?, '0001', ?, ?, ?, ?, ?, ?, ?, 'Applied', ?, NOW())");
        $stmt->execute([$id, $customerId, $sourceSaleId, $penaltyDate, $calcDate, $baseAmount, $rate, $penaltyAmount, $notes]);

        // Add penalty to customer outstanding ledger safely
        $upCust = $pdo->prepare("UPDATE customers SET outstanding = outstanding + ? WHERE id = ?");
        $upCust->execute([$penaltyAmount, $customerId]);

        // Post Journal Entry
        $jeId = generateUuid();
        $jeStmt = $pdo->prepare("INSERT INTO journal_entries (id, user_id, entry_number, entry_date, source_module, source_id, reference_number, narration, created_at) VALUES (?, '0001', ?, ?, 'OverduePenalty', ?, ?, ?, NOW())");
        $jeStmt->execute([$jeId, "PEN-$id", $penaltyDate, $id, "Penalty-$sourceSaleId", "60-Day Overdue Penalty 2%"]);

        $accRec = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1030'")->fetch()['id'] ?? null;
        $accInc = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '4010'")->fetch()['id'] ?? null;

        if ($accRec && $accInc) {
            $jl1 = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (?, '0001', ?, ?, ?, 0, 'Overdue Penalty Receivable', NOW())");
            $jl1->execute([generateUuid(), $jeId, $accRec, $penaltyAmount]);

            $jl2 = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (?, '0001', ?, ?, 0, ?, 'Penalty Income', NOW())");
            $jl2->execute([generateUuid(), $jeId, $accInc, $penaltyAmount]);
        }

        $pdo->commit();
        sendResponse(['success' => true, 'id' => $id, 'penalty_amount' => $penaltyAmount]);
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(['error' => 'Failed to apply overdue penalty: ' . $e->getMessage()], 500);
    }
}
