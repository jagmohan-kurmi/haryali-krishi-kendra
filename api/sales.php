<?php
require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT s.*, c.name AS customer_name, c.mobile AS customer_mobile FROM sales s LEFT JOIN customers c ON s.customer_id = c.id ORDER BY s.created_at DESC");
    $stmt->execute();
    $sales = $stmt->fetchAll();

    foreach ($sales as &$sale) {
        $itemsStmt = $pdo->prepare("SELECT si.*, p.name AS product_name, p.batch_number, p.mfd_date, p.expiry_date FROM sale_items si LEFT JOIN products p ON si.product_id = p.id WHERE si.sale_id = :sid");
        $itemsStmt->execute([':sid' => $sale['id']]);
        $sale['sale_items'] = $itemsStmt->fetchAll();

        $payStmt = $pdo->prepare("SELECT * FROM sale_payments WHERE sale_id = :sid ORDER BY payment_date ASC, created_at ASC");
        $payStmt->execute([':sid' => $sale['id']]);
        $sale['sale_payments'] = $payStmt->fetchAll();

        $retStmt = $pdo->prepare("SELECT * FROM customer_returns WHERE original_sale_id = :sid ORDER BY transaction_datetime DESC, created_at DESC");
        $retStmt->execute([':sid' => $sale['id']]);
        $customerReturns = $retStmt->fetchAll();
        $sale['customer_returns'] = $customerReturns;

        $returnedAmt = 0.0;
        foreach ($customerReturns as $ret) {
            $returnedAmt += (float)($ret['return_amount'] ?? 0);
        }
        $sale['returned_amount'] = $returnedAmt;
        $sale['net_amount'] = max(0, (float)$sale['total_amount'] - $returnedAmt);
    }

    sendResponse($sales);
}

if ($method === 'POST') {
    $input = getJsonInput();
    $action = $_GET['action'] ?? $input['action'] ?? '';

    // ================= CUSTOMER PAYMENT HANDLER =================
    if ($action === 'add_payment') {
        $saleId = $input['sale_id'] ?? '';
        $customerId = $input['customer_id'] ?? '';
        $amount = (float)($input['amount'] ?? 0);
        $paymentMethod = $input['payment_method'] ?? 'Cash';
        $paymentDate = trim($input['payment_date'] ?? '');
        $notes = $input['notes'] ?? null;

        if (empty($saleId) || empty($customerId)) {
            sendResponse(['error' => 'Sale ID and Customer ID are required'], 400);
        }

        if (empty($paymentDate) || $paymentDate === '0000-00-00') {
            sendResponse(['error' => 'HARD ACCOUNTING REQUIREMENT: Transaction date (payment_date) is mandatory.'], 400);
        }

        if ($amount <= 0) {
            sendResponse(['error' => 'Payment amount must be greater than 0'], 400);
        }

        try {
            $pdo->beginTransaction();

            $sStmt = $pdo->prepare("SELECT * FROM sales WHERE id = :id FOR UPDATE");
            $sStmt->execute([':id' => $saleId]);
            $sale = $sStmt->fetch();
            if (!$sale) {
                $pdo->rollBack();
                sendResponse(['error' => 'Sale invoice not found'], 404);
            }

            $currentPaid = (float)$sale['paid_amount'];
            $totalAmount = (float)$sale['total_amount'];

            // Factor in any customer returns for this sale
            $retSumStmt = $pdo->prepare("SELECT COALESCE(SUM(return_amount), 0) AS total_returned FROM customer_returns WHERE original_sale_id = :sid");
            $retSumStmt->execute([':sid' => $saleId]);
            $returnedAmount = (float)$retSumStmt->fetch()['total_returned'];
            $netAmount = max(0, $totalAmount - $returnedAmount);
            $remaining = max(0, $netAmount - $currentPaid);

            if ($amount > ($remaining + 0.05)) {
                $pdo->rollBack();
                sendResponse(['error' => 'Payment amount cannot exceed net remaining amount (' . number_format($remaining, 2) . ')'], 400);
            }

            // Insert ONE unique payment record
            $paymentId = generateUuid();
            $spStmt = $pdo->prepare("INSERT INTO sale_payments (id, user_id, sale_id, customer_id, amount, payment_method, payment_date, notes, created_at) VALUES (:id, '0001', :sale_id, :cust_id, :amt, :pmeth, :pdate, :notes, NOW())");
            $spStmt->execute([
                ':id' => $paymentId,
                ':sale_id' => $saleId,
                ':cust_id' => $customerId,
                ':amt' => $amount,
                ':pmeth' => $paymentMethod,
                ':pdate' => $paymentDate,
                ':notes' => $notes ?? ("Payment received for Sale Invoice " . $sale['invoice_number'])
            ]);

            // Recalculate aggregate sale paid amount and status
            $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM sale_payments WHERE sale_id = :sale_id");
            $sumStmt->execute([':sale_id' => $saleId]);
            $newPaidAmount = (float)$sumStmt->fetch()['total_paid'];
            $newStatus = ($newPaidAmount >= ($netAmount - 0.01)) ? 'Paid' : (($newPaidAmount > 0) ? 'Partial' : 'Unpaid');

            $upSale = $pdo->prepare("UPDATE sales SET paid_amount = :paid, status = :status WHERE id = :id");
            $upSale->execute([
                ':paid' => $newPaidAmount,
                ':status' => $newStatus,
                ':id' => $saleId
            ]);

            // Recalculate customer totals and outstanding from database aggregations
            $custSaleStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS tot_sales FROM sales WHERE customer_id = :cid");
            $custSaleStmt->execute([':cid' => $customerId]);
            $totSales = (float)$custSaleStmt->fetch()['tot_sales'];

            $custPayStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS tot_paid FROM sale_payments WHERE customer_id = :cid");
            $custPayStmt->execute([':cid' => $customerId]);
            $totPaid = (float)$custPayStmt->fetch()['tot_paid'];

            $custRetStmt = $pdo->prepare("SELECT COALESCE(SUM(return_amount), 0) AS tot_ret FROM customer_returns WHERE customer_id = :cid");
            $custRetStmt->execute([':cid' => $customerId]);
            $totReturns = (float)$custRetStmt->fetch()['tot_ret'];

            $custRow = $pdo->query("SELECT opening_balance FROM customers WHERE id = " . $pdo->quote($customerId))->fetch();
            $openingBalance = (float)($custRow['opening_balance'] ?? 0);

            $newOutstanding = max(0, $openingBalance + $totSales - $totReturns - $totPaid);

            $upCust = $pdo->prepare("UPDATE customers SET total_purchases = :tot, total_paid = :paid, outstanding = :out WHERE id = :cid");
            $upCust->execute([
                ':tot' => $totSales,
                ':paid' => $totPaid,
                ':out' => $newOutstanding,
                ':cid' => $customerId
            ]);

            // Post Double-Entry Journal Entry (Debit 1010/1020 Cash/Bank, Credit 1030 Receivables)
            $jeId = generateUuid();
            $invNum = $sale['invoice_number'];
            $jeStmt = $pdo->prepare("INSERT INTO journal_entries (id, user_id, entry_number, entry_date, source_module, source_id, reference_number, narration, created_at) VALUES (:id, '0001', :enum, :edate, 'SalePayment', :sid, :ref, :narr, NOW())");
            $jeStmt->execute([
                ':id' => $jeId,
                ':enum' => "JE-REC-$paymentId",
                ':edate' => $paymentDate,
                ':sid' => $paymentId,
                ':ref' => $invNum,
                ':narr' => "Customer Payment Received ($paymentMethod) for Invoice $invNum"
            ]);

            $accReceivable = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1030'")->fetch()['id'] ?? null;
            $accCash = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1010'")->fetch()['id'] ?? null;
            $accBank = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1020'")->fetch()['id'] ?? null;

            if ($accReceivable) {
                $payAcc = ($paymentMethod === 'Cash' && $accCash) ? $accCash : $accBank;
                if ($payAcc) {
                    $jl1 = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (:id, '0001', :jid, :aid, :dval, 0, 'Cash/Bank Inflow', NOW())");
                    $jl1->execute([
                        ':id' => generateUuid(),
                        ':jid' => $jeId,
                        ':aid' => $payAcc,
                        ':dval' => $amount
                    ]);
                }

                $jl2 = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (:id, '0001', :jid, :aid, 0, :cval, 'Customer Receivables Cleared', NOW())");
                $jl2->execute([
                    ':id' => generateUuid(),
                    ':jid' => $jeId,
                    ':aid' => $accReceivable,
                    ':cval' => $amount
                ]);
            }

            $pdo->commit();

            sendResponse([
                'success' => true,
                'payment_id' => $paymentId,
                'sale_id' => $saleId,
                'customer_id' => $customerId,
                'paid_amount' => $newPaidAmount,
                'total_amount' => $totalAmount,
                'remaining' => max(0, round($totalAmount - $newPaidAmount, 2)),
                'status' => $newStatus,
                'customer_outstanding' => $newOutstanding
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendResponse(['error' => 'Customer payment transaction failed: ' . $e->getMessage()], 500);
        }
    }

    // ================= CREATE NEW SALE HANDLER =================
    if (empty($input['customer_id']) || empty($input['items']) || !is_array($input['items'])) {
        sendResponse(['error' => 'Customer ID and items array are required'], 400);
    }

    if (empty($input['sale_date']) || $input['sale_date'] === '0000-00-00') {
        sendResponse(['error' => 'HARD ACCOUNTING REQUIREMENT: Transaction date (sale_date) is mandatory and cannot be empty.'], 400);
    }
    $customerId = trim($input['customer_id']);
    $saleDate = trim($input['sale_date']);
    $dueDate = $input['due_date'] ?? null;
    $paymentMethod = $input['payment_method'] ?? 'Cash';
    $notes = $input['notes'] ?? null;
    $proofImage = $input['proof_image'] ?? null;
    $paidAmount = (float)($input['paid_amount'] ?? 0);
    $items = $input['items'];

    $showGst = isset($input['show_gst']) ? ($input['show_gst'] ? 1 : 0) : 1;

    $totalAmount = 0;
    $totalCost = 0;

    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 0);
        $price = (float)($item['selling_price'] ?? 0);
        if ($qty <= 0 || $price < 0) {
            sendResponse(['error' => 'Item quantity must be > 0 and price >= 0'], 400);
        }

        $pStmt = $pdo->prepare("SELECT purchase_price, current_stock FROM products WHERE id = :pid");
        $pStmt->execute([':pid' => $item['product_id']]);
        $prod = $pStmt->fetch();
        if (!$prod) {
            sendResponse(['error' => "Product not found: " . $item['product_id']], 400);
        }
        if ((float)$prod['current_stock'] < $qty) {
            sendResponse(['error' => "Insufficient stock for product. Available: " . $prod['current_stock']], 400);
        }

        $totalAmount += round($qty * $price, 2);
        $totalCost += round($qty * (float)$prod['purchase_price'], 2);
    }

    if ($paidAmount < 0 || $paidAmount > $totalAmount) {
        sendResponse(['error' => 'Paid amount cannot exceed total sale amount'], 400);
    }

    $status = ($paidAmount >= $totalAmount) ? 'Paid' : (($paidAmount > 0) ? 'Partial' : 'Unpaid');

    // ATOMIC INNODB TRANSACTION
    try {
        $pdo->beginTransaction();

        $counterStmt = $pdo->prepare("SELECT last_number FROM invoice_counter WHERE user_id = '0001' FOR UPDATE");
        $counterStmt->execute();
        $cRow = $counterStmt->fetch();
        $nextNum = ($cRow['last_number'] ?? 0) + 1;

        // Check max existing sales invoice number to prevent duplicate key constraint
        $maxStmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(invoice_number, 3) AS UNSIGNED)) AS max_num FROM sales WHERE invoice_number LIKE 'S-%'");
        $maxNum = (int)($maxStmt->fetch()['max_num'] ?? 0);
        if ($nextNum <= $maxNum) {
            $nextNum = $maxNum + 1;
        }

        $upCounter = $pdo->prepare("INSERT INTO invoice_counter (user_id, last_number) VALUES ('0001', :num) ON DUPLICATE KEY UPDATE last_number = VALUES(last_number)");
        $upCounter->execute([':num' => $nextNum]);

        $invoiceNumber = 'S-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
        $saleId = generateUuid();

        // 1. Insert Sale Header
        $sStmt = $pdo->prepare("INSERT INTO sales (id, user_id, invoice_number, customer_id, sale_date, total_amount, paid_amount, payment_method, due_date, notes, proof_image, show_gst, status, created_at) VALUES (:id, '0001', :inv, :cust, :sdate, :tot, :paid, :pmeth, :ddate, :notes, :proof, :sgst, :status, NOW())");
        $sStmt->execute([
            ':id' => $saleId,
            ':inv' => $invoiceNumber,
            ':cust' => $customerId,
            ':sdate' => $saleDate,
            ':tot' => $totalAmount,
            ':paid' => $paidAmount,
            ':pmeth' => $paymentMethod,
            ':ddate' => $dueDate,
            ':notes' => $notes,
            ':proof' => $proofImage,
            ':sgst' => $showGst,
            ':status' => $status
        ]);

        // 2. Insert Items & Deduct Stock
        foreach ($items as $item) {
            $siId = generateUuid();
            $qty = (float)$item['quantity'];
            $price = (float)$item['selling_price'];
            $itemTotal = round($qty * $price, 2);

            $siStmt = $pdo->prepare("INSERT INTO sale_items (id, user_id, sale_id, product_id, quantity, unit, selling_price, total, created_at) VALUES (:id, '0001', :sale_id, :prod_id, :qty, :unit, :price, :total, NOW())");
            $siStmt->execute([
                ':id' => $siId,
                ':sale_id' => $saleId,
                ':prod_id' => $item['product_id'],
                ':qty' => $qty,
                ':unit' => $item['unit'] ?? 'Bag',
                ':price' => $price,
                ':total' => $itemTotal
            ]);

            // Deduct Stock
            $upProd = $pdo->prepare("UPDATE products SET current_stock = GREATEST(0, current_stock - :qty) WHERE id = :pid");
            $upProd->execute([':qty' => $qty, ':pid' => $item['product_id']]);

            // Update Stock Status
            $upStatus = $pdo->prepare("UPDATE products SET stock_status = IF(current_stock <= 0, 'Out of Stock', IF(current_stock <= minimum_stock, 'Low Stock', 'Healthy')) WHERE id = :pid");
            $upStatus->execute([':pid' => $item['product_id']]);

            // Stock Movement OUT
            $smStmt = $pdo->prepare("INSERT INTO stock_movements (id, user_id, product_id, type, quantity, balance_after, reference_id, reference_type, notes, created_at) VALUES (:id, '0001', :pid, 'Sale', :qty, 0, :ref_id, 'sale', :notes, NOW())");
            $smStmt->execute([
                ':id' => generateUuid(),
                ':pid' => $item['product_id'],
                ':qty' => $qty,
                ':ref_id' => $saleId,
                ':notes' => "Sale Invoice $invoiceNumber"
            ]);
        }

        // 3. Update Customer Balances
        $addUdhaar = $totalAmount - $paidAmount;
        $upCust = $pdo->prepare("UPDATE customers SET total_purchases = total_purchases + :tot, total_paid = total_paid + :paid, outstanding = outstanding + :udh WHERE id = :cid");
        $upCust->execute([
            ':tot' => $totalAmount,
            ':paid' => $paidAmount,
            ':udh' => $addUdhaar,
            ':cid' => $customerId
        ]);

        // 4. Insert Sale Payment if paidAmount > 0
        if ($paidAmount > 0) {
            $spStmt = $pdo->prepare("INSERT INTO sale_payments (id, user_id, sale_id, customer_id, amount, payment_method, payment_date, notes, created_at) VALUES (:id, '0001', :sale_id, :cust_id, :amt, :pmeth, :pdate, :notes, NOW())");
            $spStmt->execute([
                ':id' => generateUuid(),
                ':sale_id' => $saleId,
                ':cust_id' => $customerId,
                ':amt' => $paidAmount,
                ':pmeth' => $paymentMethod,
                ':pdate' => $saleDate,
                ':notes' => "Payment for Invoice $invoiceNumber"
            ]);
        }

        // 5. Post Double-Entry Journal Entry
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

        $accSales = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '4010'")->fetch()['id'] ?? null;
        $accRec = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1030'")->fetch()['id'] ?? null;
        $accCash = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1010'")->fetch()['id'] ?? null;
        $accBank = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1020'")->fetch()['id'] ?? null;

        if ($accSales) {
            $jlStmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (:id, '0001', :jid, :aid, 0, :cval, 'Sales Revenue', NOW())");
            $jlStmt->execute([
                ':id' => generateUuid(),
                ':jid' => $jeId,
                ':aid' => $accSales,
                ':cval' => $totalAmount
            ]);

            if ($paidAmount > 0) {
                $payAcc = ($paymentMethod === 'Cash' && $accCash) ? $accCash : $accBank;
                if ($payAcc) {
                    $jlStmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (:id, '0001', :jid, :aid, :dval, 0, 'Payment Received', NOW())");
                    $jlStmt->execute([
                        ':id' => generateUuid(),
                        ':jid' => $jeId,
                        ':aid' => $payAcc,
                        ':dval' => $paidAmount
                    ]);
                }
            }

            if ($addUdhaar > 0 && $accRec) {
                $jlStmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (:id, '0001', :jid, :aid, :dval, 0, 'Farmer Udhaar Khata', NOW())");
                $jlStmt->execute([
                    ':id' => generateUuid(),
                    ':jid' => $jeId,
                    ':aid' => $accRec,
                    ':dval' => $addUdhaar
                ]);
            }
        }

        $pdo->commit();

        sendResponse([
            'success' => true,
            'sale_id' => $saleId,
            'invoice_number' => $invoiceNumber,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'status' => $status
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(['error' => 'Sale transaction failed: ' . $e->getMessage()], 500);
    }
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    $purge = $_GET['purge'] ?? '';

    if ($id === 'purge_all' || $purge === 'true') {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            $pdo->exec("TRUNCATE TABLE sale_payments;");
            $pdo->exec("TRUNCATE TABLE sale_items;");
            $pdo->exec("TRUNCATE TABLE sales;");
            $pdo->exec("TRUNCATE TABLE purchase_payments;");
            $pdo->exec("TRUNCATE TABLE purchase_items;");
            $pdo->exec("TRUNCATE TABLE purchases;");
            $pdo->exec("TRUNCATE TABLE expenses;");
            $pdo->exec("TRUNCATE TABLE stock_movements;");
            if ($pdo->query("SHOW TABLES LIKE 'customer_returns'")->rowCount() > 0) {
                $pdo->exec("TRUNCATE TABLE customer_returns;");
                $pdo->exec("TRUNCATE TABLE customer_return_items;");
            }
            if ($pdo->query("SHOW TABLES LIKE 'supplier_returns'")->rowCount() > 0) {
                $pdo->exec("TRUNCATE TABLE supplier_returns;");
                $pdo->exec("TRUNCATE TABLE supplier_return_items;");
            }
            if ($pdo->query("SHOW TABLES LIKE 'customer_penalties'")->rowCount() > 0) {
                $pdo->exec("TRUNCATE TABLE customer_penalties;");
            }
            $pdo->exec("TRUNCATE TABLE customers;");
            $pdo->exec("TRUNCATE TABLE suppliers;");
            if ($pdo->query("SHOW TABLES LIKE 'journal_lines'")->rowCount() > 0) {
                $pdo->exec("TRUNCATE TABLE journal_lines;");
                $pdo->exec("TRUNCATE TABLE journal_entries;");
            }
            $pdo->exec("TRUNCATE TABLE invoice_counter;");
            $pdo->exec("INSERT INTO invoice_counter (user_id, last_number) VALUES ('0001', 0);");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

            sendResponse(['success' => true, 'message' => 'All demo transactions, sales, purchases, customers, and suppliers purged successfully!']);
        } catch (Exception $e) {
            sendResponse(['error' => 'Purge failed: ' . $e->getMessage()], 500);
        }
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($id === 'purge_all' || $id === 'all') {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            $pdo->exec("TRUNCATE TABLE sale_payments;");
            $pdo->exec("TRUNCATE TABLE sale_items;");
            $pdo->exec("TRUNCATE TABLE sales;");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            $pdo->commit();
            sendResponse(['success' => true]);
            exit;
        }

        // 1. Fetch Sale details before deletion
        $saleStmt = $pdo->prepare("SELECT * FROM sales WHERE id = :id");
        $saleStmt->execute([':id' => $id]);
        $sale = $saleStmt->fetch();

        if ($sale) {
            $customerId = $sale['customer_id'];
            $totalAmount = (float)$sale['total_amount'];
            $paidAmount = (float)$sale['paid_amount'];

            // 2. Fetch sale items and RESTORE product stock
            $itemsStmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = :id");
            $itemsStmt->execute([':id' => $id]);
            $items = $itemsStmt->fetchAll();

            foreach ($items as $item) {
                $pid = $item['product_id'];
                $qty = (float)$item['quantity'];

                $upStock = $pdo->prepare("UPDATE products SET current_stock = current_stock + :qty WHERE id = :pid");
                $upStock->execute([':qty' => $qty, ':pid' => $pid]);

                $upStatus = $pdo->prepare("UPDATE products SET stock_status = IF(current_stock <= 0, 'Out of Stock', IF(current_stock <= minimum_stock, 'Low Stock', 'Healthy')) WHERE id = :pid");
                $upStatus->execute([':pid' => $pid]);
            }

            // 3. Reverse Customer Ledger
            if ($customerId) {
                $upCust = $pdo->prepare("UPDATE customers SET 
                    total_purchases = GREATEST(0, total_purchases - :tot), 
                    total_paid = GREATEST(0, total_paid - :paid), 
                    outstanding = GREATEST(0, outstanding - :bal) 
                    WHERE id = :cid");
                $upCust->execute([
                    ':tot' => $totalAmount,
                    ':paid' => $paidAmount,
                    ':bal' => max(0, $totalAmount - $paidAmount),
                    ':cid' => $customerId
                ]);
            }
        }

        // 4. Delete transactions and journals
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $pdo->exec("DELETE FROM sale_payments WHERE sale_id = " . $pdo->quote($id));
        $pdo->exec("DELETE FROM sale_items WHERE sale_id = " . $pdo->quote($id));
        $pdo->exec("DELETE FROM journal_entries WHERE source_id = " . $pdo->quote($id));
        $pdo->exec("DELETE FROM stock_movements WHERE reference_id = " . $pdo->quote($id));
        $pdo->exec("DELETE FROM sales WHERE id = " . $pdo->quote($id));
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

        $pdo->commit();
        sendResponse(['success' => true, 'message' => 'Sale deleted, stock restored to inventory, customer ledger updated!']);
    } catch (Exception $e) {
        $pdo->rollBack();
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        sendResponse(['error' => 'Delete sale failed: ' . $e->getMessage()], 500);
    }
}

