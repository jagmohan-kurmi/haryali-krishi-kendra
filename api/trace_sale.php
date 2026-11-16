<?php
require_once __DIR__ . '/config.php';
$pdo = getDatabaseConnection();

$customerId = '26d72e48-e51e-4607-9796-a61ab6df5817';
$prodId = 'aa43bafb-b7f5-48e1-a573-b76646d14194';
$saleDate = date('Y-m-d');

try {
    $pdo->beginTransaction();
    echo "Step 1: Transaction started.
";

    $counterStmt = $pdo->prepare("SELECT last_number FROM invoice_counter WHERE user_id = '0001' FOR UPDATE");
    $counterStmt->execute();
    $cRow = $counterStmt->fetch();
    $nextNum = ($cRow['last_number'] ?? 0) + 1;
    echo "Step 2: Counter fetched ($nextNum).
";

    $upCounter = $pdo->prepare("INSERT INTO invoice_counter (user_id, last_number) VALUES ('0001', :num) ON DUPLICATE KEY UPDATE last_number = VALUES(last_number)");
    $upCounter->execute([':num' => $nextNum]);
    echo "Step 3: Counter updated.
";

    $invoiceNumber = 'S-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    $saleId = generateUuid();

    $sStmt = $pdo->prepare("INSERT INTO sales (id, user_id, invoice_number, customer_id, sale_date, total_amount, paid_amount, payment_method, due_date, notes, proof_image, status, created_at) VALUES (:id, '0001', :inv, :cust, :sdate, :tot, :paid, :pmeth, :ddate, :notes, :proof, :status, NOW())");
    $sStmt->execute([
        ':id' => $saleId,
        ':inv' => $invoiceNumber,
        ':cust' => $customerId,
        ':sdate' => $saleDate,
        ':tot' => 500,
        ':paid' => 500,
        ':pmeth' => 'Cash',
        ':ddate' => null,
        ':notes' => 'Debug',
        ':proof' => null,
        ':status' => 'Paid'
    ]);
    echo "Step 4: Sales Header inserted.
";

    $siId = generateUuid();
    $siStmt = $pdo->prepare("INSERT INTO sale_items (id, user_id, sale_id, product_id, quantity, unit, selling_price, total, created_at) VALUES (:id, '0001', :sale_id, :prod_id, :qty, :unit, :price, :total, NOW())");
    $siStmt->execute([
        ':id' => $siId,
        ':sale_id' => $saleId,
        ':prod_id' => $prodId,
        ':qty' => 2,
        ':unit' => 'Bag',
        ':price' => 250,
        ':total' => 500
    ]);
    echo "Step 5: Sale Item inserted.
";

    $upProd = $pdo->prepare("UPDATE products SET current_stock = GREATEST(0, current_stock - :qty) WHERE id = :pid");
    $upProd->execute([':qty' => 2, ':pid' => $prodId]);
    echo "Step 6: Product stock updated.
";

    $upStatus = $pdo->prepare("UPDATE products SET stock_status = IF(current_stock <= 0, 'Out of Stock', IF(current_stock <= minimum_stock, 'Low Stock', 'Healthy')) WHERE id = :pid");
    $upStatus->execute([':pid' => $prodId]);
    echo "Step 7: Stock status updated.
";

    $smStmt = $pdo->prepare("INSERT INTO stock_movements (id, user_id, product_id, type, quantity, balance_after, reference_id, reference_type, notes, created_at) VALUES (:id, '0001', :pid, 'Sale', :qty, 0, :ref_id, 'sale', :notes, NOW())");
    $smStmt->execute([
        ':id' => generateUuid(),
        ':pid' => $prodId,
        ':qty' => 2,
        ':ref_id' => $saleId,
        ':notes' => "Sale Invoice $invoiceNumber"
    ]);
    echo "Step 8: Stock movement inserted.
";

    $upCust = $pdo->prepare("UPDATE customers SET total_purchases = total_purchases + :tot, total_paid = total_paid + :paid, outstanding = outstanding + :udh WHERE id = :cid");
    $upCust->execute([
        ':tot' => 500,
        ':paid' => 500,
        ':udh' => 0,
        ':cid' => $customerId
    ]);
    echo "Step 9: Customer updated.
";

    $spStmt = $pdo->prepare("INSERT INTO sale_payments (id, user_id, sale_id, customer_id, amount, payment_method, payment_date, notes, created_at) VALUES (:id, '0001', :sale_id, :cust_id, :amt, :pmeth, :pdate, :notes, NOW())");
    $spStmt->execute([
        ':id' => generateUuid(),
        ':sale_id' => $saleId,
        ':cust_id' => $customerId,
        ':amt' => 500,
        ':pmeth' => 'Cash',
        ':pdate' => $saleDate,
        ':notes' => "Payment for Invoice $invoiceNumber"
    ]);
    echo "Step 10: Sale Payment inserted.
";

    $jeId = generateUuid();
    $jeStmt = $pdo->prepare("INSERT INTO journal_entries (id, user_id, entry_number, entry_date, source_module, source_id, reference_number, narration, created_at) VALUES (:id, '0001', :enum, :edate, 'Sales', :sid, :ref, :narr, NOW())");
    $jeStmt->execute([
        ':id' => $jeId,
        ':enum' => "JE-$invoiceNumber",
        ':edate' => $saleDate,
        ':sid' => $saleId,
        ':ref' => $invoiceNumber,
        ':narr' => "Sale Invoice $invoiceNumber"
    ]);
    echo "Step 11: Journal Entry inserted.
";

    $accSales = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '4010'")->fetch()['id'] ?? null;
    $accCash = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1010'")->fetch()['id'] ?? null;

    if ($accSales && $accCash) {
        $jlStmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (:id, '0001', :jid, :aid, 0, :cval, 'Sales Revenue', NOW())");
        $jlStmt->execute([
            ':id' => generateUuid(),
            ':jid' => $jeId,
            ':aid' => $accSales,
            ':cval' => 500
        ]);
        echo "Step 12: Journal Line 1 inserted.
";

        $jlStmt2 = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (:id, '0001', :jid, :aid, :dval, 0, 'Payment Received', NOW())");
        $jlStmt2->execute([
            ':id' => generateUuid(),
            ':jid' => $jeId,
            ':aid' => $accCash,
            ':dval' => 500
        ]);
        echo "Step 13: Journal Line 2 inserted.
";
    }

    $pdo->commit();
    echo "SUCCESS: ALL STEPS EXECUTED AND COMMITTED!
";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "FAILED AT STEP: " . $e->getMessage() . "
";
}
