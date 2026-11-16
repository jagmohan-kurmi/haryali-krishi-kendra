<?php
/**
 * Master Return Management System API
 * Kisan Krishi Sewa Kendra ERP
 * Supports Customer Sales Returns (SR-00001) & Supplier Purchase Returns (PR-00001)
 */

require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'];
$type = $_GET['type'] ?? 'customer';

if ($method === 'GET') {
    if ($type === 'supplier') {
        $stmt = $pdo->prepare("SELECT r.*, s.name as supplier_name, p.invoice_number as original_purchase_number FROM supplier_returns r JOIN suppliers s ON r.supplier_id = s.id LEFT JOIN purchases p ON r.original_purchase_id = p.id ORDER BY r.transaction_datetime DESC, r.created_at DESC");
        $stmt->execute();
        $returns = $stmt->fetchAll();

        foreach ($returns as &$ret) {
            $itemStmt = $pdo->prepare("SELECT ri.*, p.name as product_name FROM supplier_return_items ri JOIN products p ON ri.product_id = p.id WHERE ri.return_id = ?");
            $itemStmt->execute([$ret['id']]);
            $ret['items'] = $itemStmt->fetchAll();
        }
        sendResponse($returns);
    } else {
        $stmt = $pdo->prepare("SELECT r.*, c.name as customer_name, s.invoice_number as original_sale_number FROM customer_returns r JOIN customers c ON r.customer_id = c.id LEFT JOIN sales s ON r.original_sale_id = s.id ORDER BY r.transaction_datetime DESC, r.created_at DESC");
        $stmt->execute();
        $returns = $stmt->fetchAll();

        foreach ($returns as &$ret) {
            $itemStmt = $pdo->prepare("SELECT ri.*, p.name as product_name FROM customer_return_items ri JOIN products p ON ri.product_id = p.id WHERE ri.return_id = ?");
            $itemStmt->execute([$ret['id']]);
            $ret['items'] = $itemStmt->fetchAll();
        }
        sendResponse($returns);
    }
}

if ($method === 'POST') {
    $input = getJsonInput();

    // HARD MANDATORY TRANSACTION DATE & TIME VALIDATION
    $returnDate = trim($input['return_date'] ?? $input['transaction_date'] ?? date('Y-m-d'));
    $returnTime = trim($input['return_time'] ?? date('h:i:s A'));
    $txDatetime = trim($input['transaction_datetime'] ?? date('Y-m-d H:i:s'));

    if (empty($returnDate) || $returnDate === '0000-00-00') {
        sendResponse(['error' => 'Transaction date is mandatory and cannot be empty.'], 400);
    }

    if ($type === 'supplier') {
        // ==================== SUPPLIER PURCHASE RETURN (PR-) ====================
        $supplierId = $input['supplier_id'] ?? '';
        $purchaseId = $input['original_purchase_id'] ?? '';
        $items = $input['items'] ?? [];
        $returnAmount = (float)($input['return_amount'] ?? 0);
        $reason = trim($input['reason'] ?? 'Supplier Replacement');
        $paymentMode = trim($input['payment_mode'] ?? 'PAYABLE_ADJUSTMENT');
        $refundStatus = trim($input['refund_status'] ?? ($paymentMode === 'PAYABLE_ADJUSTMENT' ? 'ADJUSTED' : 'REFUNDED'));
        $notes = trim($input['notes'] ?? '');

        if (!$supplierId || !$purchaseId || empty($items)) {
            sendResponse(['error' => 'Supplier ID, Purchase ID, and return items are required.'], 400);
        }

        $pdo->beginTransaction();
        try {
            // 1. Fetch Original Purchase & Items
            $purStmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ? FOR UPDATE");
            $purStmt->execute([$purchaseId]);
            $origPurchase = $purStmt->fetch();
            if (!$origPurchase) {
                throw new Exception("Original Purchase Invoice not found.");
            }

            // 2. Generate Return Number with PR- Prefix
            $countStmt = $pdo->query("SELECT COUNT(*) FROM supplier_returns");
            $nextSeq = (int)$countStmt->fetchColumn() + 1;
            $returnNum = sprintf("PR-%05d", $nextSeq);

            // Double check uniqueness
            $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM supplier_returns WHERE return_number = ?");
            $chkStmt->execute([$returnNum]);
            if ((int)$chkStmt->fetchColumn() > 0) {
                $returnNum = 'PR-' . date('Ymd-His') . '-' . mt_rand(10, 99);
            }

            $id = generateUuid();

            // Insert Header
            $stmt = $pdo->prepare("INSERT INTO supplier_returns (id, user_id, return_number, original_purchase_id, supplier_id, return_date, return_time, transaction_datetime, return_amount, reason, payment_mode, refund_status, notes, created_at) VALUES (?, '0001', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$id, $returnNum, $purchaseId, $supplierId, $returnDate, $returnTime, $txDatetime, $returnAmount, $reason, $paymentMode, $refundStatus, $notes]);

            $calculatedTotalReturn = 0;

            foreach ($items as $item) {
                $prodId = $item['product_id'];
                $qty = (float)$item['quantity'];
                $unit = $item['unit'] ?? 'Unit';
                $price = (float)$item['unit_price'];
                $batchNo = trim($item['batch_number'] ?? '');
                $expDate = !empty($item['expiry_date']) ? $item['expiry_date'] : null;

                if ($qty <= 0) {
                    continue;
                }

                // Check original qty on purchase item
                $piStmt = $pdo->prepare("SELECT quantity FROM purchase_items WHERE purchase_id = ? AND product_id = ?");
                $piStmt->execute([$purchaseId, $prodId]);
                $origQty = (float)$piStmt->fetchColumn();

                // Check previously returned qty for this purchase & product
                $prevStmt = $pdo->prepare("SELECT SUM(sri.quantity) FROM supplier_return_items sri JOIN supplier_returns sr ON sri.return_id = sr.id WHERE sr.original_purchase_id = ? AND sri.product_id = ?");
                $prevStmt->execute([$purchaseId, $prodId]);
                $prevReturned = (float)$prevStmt->fetchColumn();

                $remainingQty = max(0, $origQty - $prevReturned);

                if ($qty > $remainingQty + 0.0001) {
                    $prodNameStmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
                    $prodNameStmt->execute([$prodId]);
                    $pName = $prodNameStmt->fetchColumn() ?: 'Product';
                    throw new Exception("Only {$remainingQty} units of {$pName} are available for return on Purchase Invoice {$origPurchase['invoice_number']}. Attempted: {$qty}.");
                }

                $total = $qty * $price;
                $calculatedTotalReturn += $total;

                $itemId = generateUuid();
                $itemStmt = $pdo->prepare("INSERT INTO supplier_return_items (id, user_id, return_id, product_id, quantity, unit, unit_price, total, batch_number, expiry_date, created_at) VALUES (?, '0001', ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $itemStmt->execute([$itemId, $id, $prodId, $qty, $unit, $price, $total, $batchNo, $expDate]);

                // Stock Movement OUT (-2)
                $stockStmt = $pdo->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ?");
                $stockStmt->execute([$qty, $prodId]);

                $upStatus = $pdo->prepare("UPDATE products SET stock_status = IF(current_stock <= 0, 'Out of Stock', IF(current_stock <= minimum_stock, 'Low Stock', 'Healthy')) WHERE id = ?");
                $upStatus->execute([$prodId]);

                $balStmt = $pdo->prepare("SELECT current_stock FROM products WHERE id = ?");
                $balStmt->execute([$prodId]);
                $curStock = (float)$balStmt->fetchColumn();

                $smStmt = $pdo->prepare("INSERT INTO stock_movements (id, user_id, product_id, type, quantity, balance_after, reference_id, reference_type, notes, created_at) VALUES (?, '0001', ?, 'RETURN_OUT', ?, ?, ?, 'SUPPLIER_RETURN', ?, NOW())");
                $smStmt->execute([generateUuid(), $prodId, -$qty, $curStock, $id, "Purchase Return Voucher #{$returnNum}"]);
            }

            // Update header total if different
            if ($calculatedTotalReturn > 0 && abs($calculatedTotalReturn - $returnAmount) > 0.01) {
                $returnAmount = $calculatedTotalReturn;
                $upRetHead = $pdo->prepare("UPDATE supplier_returns SET return_amount = ? WHERE id = ?");
                $upRetHead->execute([$returnAmount, $id]);
            }

            // Option B: If Refund Received from Supplier directly
            if ($paymentMode !== 'PAYABLE_ADJUSTMENT') {
                $payId = generateUuid();
                $payStmt = $pdo->prepare("INSERT INTO purchase_payments (id, user_id, purchase_id, supplier_id, amount, payment_method, payment_date, notes, created_at) VALUES (?, '0001', ?, ?, ?, ?, ?, ?, NOW())");
                $payStmt->execute([$payId, $purchaseId, $supplierId, $returnAmount, $paymentMode, $returnDate, "Refund for Purchase Return #{$returnNum}"]);
            }

            // Recalculate Supplier Payable & Khata
            $totPurStmt = $pdo->prepare("SELECT SUM(total_amount) FROM purchases WHERE supplier_id = ?");
            $totPurStmt->execute([$supplierId]);
            $totPurchases = (float)$totPurStmt->fetchColumn();

            $totPayStmt = $pdo->prepare("SELECT SUM(amount) FROM purchase_payments WHERE supplier_id = ?");
            $totPayStmt->execute([$supplierId]);
            $totPaid = (float)$totPayStmt->fetchColumn();

            $totRetStmt = $pdo->prepare("SELECT SUM(return_amount) FROM supplier_returns WHERE supplier_id = ?");
            $totRetStmt->execute([$supplierId]);
            $totReturns = (float)$totRetStmt->fetchColumn();

            $opStmt = $pdo->prepare("SELECT opening_payable FROM suppliers WHERE id = ?");
            $opStmt->execute([$supplierId]);
            $opPayable = (float)$opStmt->fetchColumn();

            $netPurchases = max(0, $totPurchases - $totReturns);
            $outstanding = max(0, ($opPayable + $netPurchases) - $totPaid);

            $upSup = $pdo->prepare("UPDATE suppliers SET total_purchases = ?, total_paid = ?, outstanding = ? WHERE id = ?");
            $upSup->execute([$netPurchases, $totPaid, $outstanding, $supplierId]);

            // Auto-post CA Journal Entry
            try {
                $jId = generateUuid();
                $countJ = $pdo->query("SELECT COUNT(*) FROM journal_entries");
                $nextJSeq = (int)$countJ->fetchColumn() + 1;
                $entryNum = sprintf("JE-%05d", $nextJSeq);

                $jStmt = $pdo->prepare("INSERT INTO journal_entries (id, user_id, entry_number, entry_date, source_module, source_id, reference_number, narration, created_at) VALUES (?, '0001', ?, ?, 'ADJUSTMENT', ?, ?, ?, NOW())");
                $jStmt->execute([$jId, $entryNum, $returnDate, $id, $returnNum, "Purchase Return Voucher #{$returnNum} for Purchase {$origPurchase['invoice_number']}"]);

                // Debit Supplier Payable (2010), Credit Purchase Return / Stock (5010)
                $l1 = generateUuid();
                $l1Stmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, notes, created_at) VALUES (?, '0001', ?, '2010', ?, 0.00, 'Supplier Payable Adjustment', NOW())");
                $l1Stmt->execute([$l1, $jId, $returnAmount]);

                $l2 = generateUuid();
                $l2Stmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, notes, created_at) VALUES (?, '0001', ?, '5010', 0.00, ?, 'Purchase Return Inventory Reversal', NOW())");
                $l2Stmt->execute([$l2, $jId, $returnAmount]);
            } catch (Exception $je) {
                // Ignore secondary journal fail
            }

            $pdo->commit();
            sendResponse(['success' => true, 'id' => $id, 'return_number' => $returnNum, 'return_amount' => $returnAmount]);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendResponse(['error' => $e->getMessage()], 400);
        }
    } else {
        // ==================== CUSTOMER SALES RETURN (SR-) ====================
        $customerId = $input['customer_id'] ?? '';
        $saleId = $input['original_sale_id'] ?? '';
        $items = $input['items'] ?? [];
        $returnAmount = (float)($input['return_amount'] ?? 0);
        $reason = trim($input['reason'] ?? 'Customer Returned Product');
        $paymentMode = trim($input['payment_mode'] ?? 'KHATA_ADJUSTMENT');
        $refundStatus = trim($input['refund_status'] ?? ($paymentMode === 'KHATA_ADJUSTMENT' ? 'ADJUSTED' : 'REFUNDED'));
        $notes = trim($input['notes'] ?? '');

        if (!$customerId || !$saleId || empty($items)) {
            sendResponse(['error' => 'Customer ID, Sale ID, and return items are required.'], 400);
        }

        $pdo->beginTransaction();
        try {
            // 1. Fetch Original Sale & Items
            $salStmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
            $salStmt->execute([$saleId]);
            $origSale = $salStmt->fetch();
            if (!$origSale) {
                throw new Exception("Original Sales Invoice not found.");
            }

            // 2. Generate Return Number with SR- Prefix
            $countStmt = $pdo->query("SELECT COUNT(*) FROM customer_returns");
            $nextSeq = (int)$countStmt->fetchColumn() + 1;
            $returnNum = sprintf("SR-%05d", $nextSeq);

            // Double check uniqueness
            $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM customer_returns WHERE return_number = ?");
            $chkStmt->execute([$returnNum]);
            if ((int)$chkStmt->fetchColumn() > 0) {
                $returnNum = 'SR-' . date('Ymd-His') . '-' . mt_rand(10, 99);
            }

            $id = generateUuid();

            // Insert Header
            $stmt = $pdo->prepare("INSERT INTO customer_returns (id, user_id, return_number, original_sale_id, customer_id, return_date, return_time, transaction_datetime, return_amount, reason, payment_mode, refund_status, notes, created_at) VALUES (?, '0001', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$id, $returnNum, $saleId, $customerId, $returnDate, $returnTime, $txDatetime, $returnAmount, $reason, $paymentMode, $refundStatus, $notes]);

            $calculatedTotalReturn = 0;

            foreach ($items as $item) {
                $prodId = $item['product_id'];
                $qty = (float)$item['quantity'];
                $unit = $item['unit'] ?? 'Unit';
                $price = (float)$item['unit_price'];
                $batchNo = trim($item['batch_number'] ?? '');
                $expDate = !empty($item['expiry_date']) ? $item['expiry_date'] : null;

                if ($qty <= 0) {
                    continue;
                }

                // Check original qty on sale item
                $siStmt = $pdo->prepare("SELECT quantity FROM sale_items WHERE sale_id = ? AND product_id = ?");
                $siStmt->execute([$saleId, $prodId]);
                $origQty = (float)$siStmt->fetchColumn();

                // Check previously returned qty for this sale & product
                $prevStmt = $pdo->prepare("SELECT SUM(cri.quantity) FROM customer_return_items cri JOIN customer_returns cr ON cri.return_id = cr.id WHERE cr.original_sale_id = ? AND cri.product_id = ?");
                $prevStmt->execute([$saleId, $prodId]);
                $prevReturned = (float)$prevStmt->fetchColumn();

                $remainingQty = max(0, $origQty - $prevReturned);

                if ($qty > $remainingQty + 0.0001) {
                    $prodNameStmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
                    $prodNameStmt->execute([$prodId]);
                    $pName = $prodNameStmt->fetchColumn() ?: 'Product';
                    throw new Exception("Only {$remainingQty} units of {$pName} are available for return on Sales Invoice {$origSale['invoice_number']}. Attempted: {$qty}.");
                }

                $total = $qty * $price;
                $calculatedTotalReturn += $total;

                $itemId = generateUuid();
                $itemStmt = $pdo->prepare("INSERT INTO customer_return_items (id, user_id, return_id, product_id, quantity, unit, unit_price, total, batch_number, expiry_date, created_at) VALUES (?, '0001', ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $itemStmt->execute([$itemId, $id, $prodId, $qty, $unit, $price, $total, $batchNo, $expDate]);

                // Stock Movement IN (+2)
                $stockStmt = $pdo->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
                $stockStmt->execute([$qty, $prodId]);

                $upStatus = $pdo->prepare("UPDATE products SET stock_status = IF(current_stock <= 0, 'Out of Stock', IF(current_stock <= minimum_stock, 'Low Stock', 'Healthy')) WHERE id = ?");
                $upStatus->execute([$prodId]);

                $balStmt = $pdo->prepare("SELECT current_stock FROM products WHERE id = ?");
                $balStmt->execute([$prodId]);
                $curStock = (float)$balStmt->fetchColumn();

                $smStmt = $pdo->prepare("INSERT INTO stock_movements (id, user_id, product_id, type, quantity, balance_after, reference_id, reference_type, notes, created_at) VALUES (?, '0001', ?, 'RETURN_IN', ?, ?, ?, 'CUSTOMER_RETURN', ?, NOW())");
                $smStmt->execute([generateUuid(), $prodId, $qty, $curStock, $id, "Sales Return Invoice #{$returnNum}"]);
            }

            // Update header total if calculated differs
            if ($calculatedTotalReturn > 0 && abs($calculatedTotalReturn - $returnAmount) > 0.01) {
                $returnAmount = $calculatedTotalReturn;
                $upRetHead = $pdo->prepare("UPDATE customer_returns SET return_amount = ? WHERE id = ?");
                $upRetHead->execute([$returnAmount, $id]);
            }

            // Option B: If Refund Paid to Customer directly
            if ($paymentMode !== 'KHATA_ADJUSTMENT') {
                $payId = generateUuid();
                $payStmt = $pdo->prepare("INSERT INTO sale_payments (id, user_id, sale_id, customer_id, amount, payment_method, payment_date, notes, created_at) VALUES (?, '0001', ?, ?, ?, ?, ?, ?, NOW())");
                $payStmt->execute([$payId, $saleId, $customerId, $returnAmount, $paymentMode, $returnDate, "Refund for Sales Return #{$returnNum}"]);
            }

            // Recalculate Customer Khata & Balance
            $totSalStmt = $pdo->prepare("SELECT SUM(total_amount) FROM sales WHERE customer_id = ?");
            $totSalStmt->execute([$customerId]);
            $totSales = (float)$totSalStmt->fetchColumn();

            $totPayStmt = $pdo->prepare("SELECT SUM(amount) FROM sale_payments WHERE customer_id = ?");
            $totPayStmt->execute([$customerId]);
            $totPaid = (float)$totPayStmt->fetchColumn();

            $totRetStmt = $pdo->prepare("SELECT SUM(return_amount) FROM customer_returns WHERE customer_id = ?");
            $totRetStmt->execute([$customerId]);
            $totReturns = (float)$totRetStmt->fetchColumn();

            $opStmt = $pdo->prepare("SELECT opening_balance FROM customers WHERE id = ?");
            $opStmt->execute([$customerId]);
            $opBal = (float)$opStmt->fetchColumn();

            $penStmt = $pdo->prepare("SELECT SUM(penalty_amount) FROM customer_penalties WHERE customer_id = ? AND status = 'Applied'");
            $penStmt->execute([$customerId]);
            $totPenalties = (float)$penStmt->fetchColumn();

            $netSales = max(0, $totSales - $totReturns);
            $outstanding = max(0, ($opBal + $netSales + $totPenalties) - $totPaid);

            $upCust = $pdo->prepare("UPDATE customers SET total_purchases = ?, total_paid = ?, outstanding = ? WHERE id = ?");
            $upCust->execute([$netSales, $totPaid, $outstanding, $customerId]);

            // Auto-post CA Journal Entry
            try {
                $jId = generateUuid();
                $countJ = $pdo->query("SELECT COUNT(*) FROM journal_entries");
                $nextJSeq = (int)$countJ->fetchColumn() + 1;
                $entryNum = sprintf("JE-%05d", $nextJSeq);

                $jStmt = $pdo->prepare("INSERT INTO journal_entries (id, user_id, entry_number, entry_date, source_module, source_id, reference_number, narration, created_at) VALUES (?, '0001', ?, ?, 'ADJUSTMENT', ?, ?, ?, NOW())");
                $jStmt->execute([$jId, $entryNum, $returnDate, $id, $returnNum, "Sales Return Invoice #{$returnNum} for Sale {$origSale['invoice_number']}"]);

                // Debit Sales Returns (4010), Credit Customer Accounts Receivable (1020)
                $l1 = generateUuid();
                $l1Stmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, notes, created_at) VALUES (?, '0001', ?, '4010', ?, 0.00, 'Sales Returns & Allowances', NOW())");
                $l1Stmt->execute([$l1, $jId, $returnAmount]);

                $l2 = generateUuid();
                $l2Stmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, notes, created_at) VALUES (?, '0001', ?, '1020', 0.00, ?, 'Customer Receivable Adjustment', NOW())");
                $l2Stmt->execute([$l2, $jId, $returnAmount]);
            } catch (Exception $je) {
                // Ignore secondary journal fail
            }

            $pdo->commit();
            sendResponse(['success' => true, 'id' => $id, 'return_number' => $returnNum, 'return_amount' => $returnAmount]);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendResponse(['error' => $e->getMessage()], 400);
        }
    }
}
