<?php
require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM expenses ORDER BY expense_date DESC, created_at DESC");
    $stmt->execute();
    sendResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    $input = getJsonInput();
    if (empty($input['title']) || empty($input['amount'])) {
        sendResponse(['error' => 'Title and amount are required'], 400);
    }

    $id = generateUuid();
    $userId = '0001';
    $category = $input['category'] ?? 'Shop Expense';
    $title = trim($input['title']);
    $staffName = $input['staff_name'] ?? null;
    $salaryMonth = $input['salary_month'] ?? null;
    $amount = (float)$input['amount'];
    $expenseDate = $input['expense_date'] ?? date('Y-m-d');
    $paymentMethod = $input['payment_method'] ?? 'Cash';
    $notes = $input['notes'] ?? null;

    if ($amount <= 0) {
        sendResponse(['error' => 'Expense amount must be > 0'], 400);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO expenses (id, user_id, category, title, staff_name, salary_month, amount, expense_date, payment_method, notes, created_at) VALUES (:id, '0001', :category, :title, :staff_name, :salary_month, :amount, :expense_date, :payment_method, :notes, NOW())");
        $stmt->execute([
            ':id' => $id,
            ':category' => $category,
            ':title' => $title,
            ':staff_name' => $staffName,
            ':salary_month' => $salaryMonth,
            ':amount' => $amount,
            ':expense_date' => $expenseDate,
            ':payment_method' => $paymentMethod,
            ':notes' => $notes
        ]);

        // Post Double Entry Journal Entry
        $accCode = ($category === 'Staff Salary') ? '5030' : '5020';
        $accExp = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '$accCode'")->fetch()['id'] ?? null;
        $accCash = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1010'")->fetch()['id'] ?? null;
        $accBank = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1020'")->fetch()['id'] ?? null;

        if ($accExp) {
            $jeId = generateUuid();
            $ref = 'EXP-' . date('Ymd', strtotime($expenseDate));
            $jeStmt = $pdo->prepare("INSERT INTO journal_entries (id, user_id, entry_number, entry_date, source_module, source_id, reference_number, narration, created_at) VALUES (?, '0001', ?, ?, 'Expenses', ?, ?, ?, NOW())");
            $jeStmt->execute([$jeId, "JE-$ref", $expenseDate, $id, $ref, $title]);

            // Debit Expense Account
            $jlStmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo) VALUES (?, '0001', ?, ?, ?, 0, ?)");
            $jlStmt->execute([generateUuid(), $jeId, $accExp, $amount, $title]);

            // Credit Cash or Bank Account
            $payAcc = ($paymentMethod === 'Cash' && $accCash) ? $accCash : $accBank;
            if ($payAcc) {
                $jlStmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo) VALUES (?, '0001', ?, ?, 0, ?, 'Expense Payment')");
                $jlStmt->execute([generateUuid(), $jeId, $payAcc, $amount]);
            }
        }

        $pdo->commit();

        $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
        $stmt->execute([$id]);
        sendResponse($stmt->fetch());
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(['error' => 'Expense creation failed: ' . $e->getMessage()], 500);
    }
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) sendResponse(['error' => 'Expense ID required'], 400);

    $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
    $stmt->execute([$id]);
    sendResponse(['success' => true]);
}
