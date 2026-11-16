<?php
require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();

try {
    $pdo->beginTransaction();

    // 1. Update purchase P-0037
    $pStmt = $pdo->prepare("UPDATE purchases SET paid_amount = 6607.22, status = 'Partial' WHERE invoice_number = 'P-0037'");
    $pStmt->execute();

    // 2. Update purchase payment record
    $payStmt = $pdo->prepare("UPDATE purchase_payments SET amount = 6607.22 WHERE purchase_id IN (SELECT id FROM purchases WHERE invoice_number = 'P-0037')");
    $payStmt->execute();

    // 3. Update supplier record
    $supStmt = $pdo->prepare("UPDATE suppliers SET total_paid = 6607.22, outstanding = 100000.00 WHERE id = 'faa8385a-4d67-4782-b0e0-b46abf98a007'");
    $supStmt->execute();

    $pdo->commit();
    sendResponse(['success' => true, 'message' => 'P-0037 updated: Total=106607.22, Paid=6607.22, Remaining=100000.00, Supplier Outstanding=100000.00']);
} catch (Exception $e) {
    $pdo->rollBack();
    sendResponse(['error' => $e->getMessage()], 500);
}
