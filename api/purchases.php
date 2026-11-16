<?php
require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'];

if (!function_exists('normalizeAgroProductNamePHP')) {
    function normalizeAgroProductNamePHP($name) {
        if (empty($name)) return '';
        $str = strtolower(trim($name));
        $str = preg_replace('/^(insecticides\s+india|bayer\s+cropscience|bayer|syngenta\s+india|syngenta|upl\s+limited|upl|iffco|kribhco|tata\s+rallis|dhanuka|fmc|crystal|indofil)\s+/i', '', $str);
        $str = str_replace('inazethapyr', 'imazethapyr', $str);
        $str = str_replace('500mix12', '500ml', $str);
        $str = str_replace('500mlx12', '500ml', $str);
        $str = str_replace('1ltrx6', '1ltr', $str);
        $str = str_replace('1000ml', '1ltr', $str);
        $str = str_replace('1 litre', '1ltr', $str);
        $str = str_replace('1 liter', '1ltr', $str);
        $str = preg_replace('/[\s%+\.\-\/()]+/', ' ', $str);
        return trim($str);
    }
}

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT p.*, COALESCE(s.name, 'Agro Supplier') AS supplier_name, COALESCE(s.mobile, 'N/A') AS supplier_mobile FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id ORDER BY p.created_at DESC");
    $stmt->execute();
    $purchases = $stmt->fetchAll();

    foreach ($purchases as &$pur) {
        $itemsStmt = $pdo->prepare("SELECT pi.*, COALESCE(pr.name, 'Agro Product') AS product_name, pr.category, pr.brand, pr.batch_number, pr.expiry_date, pr.gst_rate, pr.sku FROM purchase_items pi LEFT JOIN products pr ON pi.product_id = pr.id WHERE pi.purchase_id = :pid");
        $itemsStmt->execute([':pid' => $pur['id']]);
        $items = $itemsStmt->fetchAll();
        foreach ($items as &$it) {
            $it['product'] = [
                'id' => $it['product_id'],
                'name' => $it['product_name'],
                'sku' => $it['sku'] ?? null,
                'category' => $it['category'] ?? null,
                'brand' => $it['brand'] ?? null,
                'batch_number' => $it['batch_number'] ?? null,
                'expiry_date' => $it['expiry_date'] ?? null,
                'gst_rate' => isset($it['gst_rate']) ? (float)$it['gst_rate'] : null,
                'hsn_code' => !empty($it['sku']) && preg_match('/^\d{4,8}$/', $it['sku']) ? $it['sku'] : null
            ];
        }
        $pur['purchase_items'] = $items;

        $payStmt = $pdo->prepare("SELECT * FROM purchase_payments WHERE purchase_id = :pid ORDER BY payment_date ASC, created_at ASC");
        $payStmt->execute([':pid' => $pur['id']]);
        $pur['purchase_payments'] = $payStmt->fetchAll();

        $retStmt = $pdo->prepare("SELECT * FROM supplier_returns WHERE original_purchase_id = :pid ORDER BY transaction_datetime DESC, created_at DESC");
        $retStmt->execute([':pid' => $pur['id']]);
        $supplierReturns = $retStmt->fetchAll();
        $pur['supplier_returns'] = $supplierReturns;

        $returnedAmt = 0.0;
        foreach ($supplierReturns as $ret) {
            $returnedAmt += (float)($ret['return_amount'] ?? 0);
        }
        $pur['returned_amount'] = $returnedAmt;
        $pur['net_amount'] = max(0, (float)$pur['total_amount'] - $returnedAmt);
    }

    sendResponse($purchases);
}

if ($method === 'POST') {
    $input = getJsonInput();
    $action = $_GET['action'] ?? $input['action'] ?? '';

    // ================= SUPPLIER PAYMENT HANDLER =================
    if ($action === 'add_payment') {
        $purchaseId = $input['purchase_id'] ?? '';
        $supplierId = $input['supplier_id'] ?? '';
        $amount = (float)($input['amount'] ?? 0);
        $paymentMethod = $input['payment_method'] ?? 'Cash';
        $paymentDate = trim($input['payment_date'] ?? '');
        $notes = $input['notes'] ?? null;

        if (empty($purchaseId) || empty($supplierId)) {
            sendResponse(['error' => 'Purchase ID and Supplier ID are required'], 400);
        }

        if (empty($paymentDate) || $paymentDate === '0000-00-00') {
            sendResponse(['error' => 'HARD ACCOUNTING REQUIREMENT: Transaction date (payment_date) is mandatory.'], 400);
        }

        if ($amount <= 0) {
            sendResponse(['error' => 'Payment amount must be greater than 0'], 400);
        }

        try {
            $pdo->beginTransaction();

            $pStmt = $pdo->prepare("SELECT * FROM purchases WHERE id = :id FOR UPDATE");
            $pStmt->execute([':id' => $purchaseId]);
            $purchase = $pStmt->fetch();
            if (!$purchase) {
                $pdo->rollBack();
                sendResponse(['error' => 'Purchase order not found'], 404);
            }

            // Ensure Supplier exists in suppliers table
            $chkSup = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
            $chkSup->execute([$supplierId]);
            if (!$chkSup->fetch()) {
                $supName = !empty($input['supplier_name']) ? trim($input['supplier_name']) : "Supplier $supplierId";
                $insSup = $pdo->prepare("INSERT INTO suppliers (id, user_id, name, type, opening_payable, total_purchases, total_paid, outstanding, created_at) VALUES (:id, '0001', :name, 'Distributor', 0, 0, 0, 0, NOW())");
                $insSup->execute([
                    ':id' => $supplierId,
                    ':name' => $supName
                ]);
            }

            $currentPaid = (float)$purchase['paid_amount'];
            $totalAmount = (float)$purchase['total_amount'];

            // Factor in any supplier returns for this purchase
            $retSumStmt = $pdo->prepare("SELECT COALESCE(SUM(return_amount), 0) AS total_returned FROM supplier_returns WHERE original_purchase_id = :pid");
            $retSumStmt->execute([':pid' => $purchaseId]);
            $returnedAmount = (float)$retSumStmt->fetch()['total_returned'];
            $netAmount = max(0, $totalAmount - $returnedAmount);
            $remaining = max(0, $netAmount - $currentPaid);

            if ($amount > ($remaining + 0.05)) {
                $pdo->rollBack();
                sendResponse(['error' => 'Payment amount cannot exceed net remaining payable amount (' . number_format($remaining, 2) . ')'], 400);
            }

            // Insert ONE unique payment record
            $paymentId = generateUuid();
            $ppStmt = $pdo->prepare("INSERT INTO purchase_payments (id, user_id, purchase_id, supplier_id, amount, payment_method, payment_date, notes, created_at) VALUES (:id, '0001', :pur_id, :sup_id, :amt, :pmeth, :pdate, :notes, NOW())");
            $ppStmt->execute([
                ':id' => $paymentId,
                ':pur_id' => $purchaseId,
                ':sup_id' => $supplierId,
                ':amt' => $amount,
                ':pmeth' => $paymentMethod,
                ':pdate' => $paymentDate,
                ':notes' => $notes ?? ("Payment for Purchase Invoice " . $purchase['invoice_number'])
            ]);

            // Recalculate aggregate purchase paid amount and status
            $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM purchase_payments WHERE purchase_id = :pur_id");
            $sumStmt->execute([':pur_id' => $purchaseId]);
            $newPaidAmount = (float)$sumStmt->fetch()['total_paid'];
            $newStatus = ($newPaidAmount >= ($netAmount - 0.01)) ? 'Paid' : (($newPaidAmount > 0) ? 'Partial' : 'Unpaid');

            $upPur = $pdo->prepare("UPDATE purchases SET paid_amount = :paid, status = :status WHERE id = :id");
            $upPur->execute([
                ':paid' => $newPaidAmount,
                ':status' => $newStatus,
                ':id' => $purchaseId
            ]);

            // Recalculate supplier totals and outstanding from database aggregations
            $supPurStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS tot_pur FROM purchases WHERE supplier_id = :sid");
            $supPurStmt->execute([':sid' => $supplierId]);
            $totPurchases = (float)$supPurStmt->fetch()['tot_pur'];

            $supPayStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS tot_paid FROM purchase_payments WHERE supplier_id = :sid");
            $supPayStmt->execute([':sid' => $supplierId]);
            $totPaid = (float)$supPayStmt->fetch()['tot_paid'];

            $supRetStmt = $pdo->prepare("SELECT COALESCE(SUM(return_amount), 0) AS tot_ret FROM supplier_returns WHERE supplier_id = :sid");
            $supRetStmt->execute([':sid' => $supplierId]);
            $totReturns = (float)$supRetStmt->fetch()['tot_ret'];

            $supRow = $pdo->query("SELECT opening_payable FROM suppliers WHERE id = " . $pdo->quote($supplierId))->fetch();
            $openingPayable = (float)($supRow['opening_payable'] ?? 0);

            $newOutstanding = max(0, $openingPayable + $totPurchases - $totReturns - $totPaid);

            $upSup = $pdo->prepare("UPDATE suppliers SET total_purchases = :tot, total_paid = :paid, outstanding = :out WHERE id = :sid");
            $upSup->execute([
                ':tot' => $totPurchases,
                ':paid' => $totPaid,
                ':out' => $newOutstanding,
                ':sid' => $supplierId
            ]);

            // Post ONE Double-Entry Journal Entry (Debit 2010 Payable, Credit 1010/1020 Cash/Bank)
            $jeId = generateUuid();
            $invNum = $purchase['invoice_number'];
            $jeStmt = $pdo->prepare("INSERT INTO journal_entries (id, user_id, entry_number, entry_date, source_module, source_id, reference_number, narration, created_at) VALUES (:id, '0001', :enum, :edate, 'PurchasePayment', :sid, :ref, :narr, NOW())");
            $jeStmt->execute([
                ':id' => $jeId,
                ':enum' => "JE-PAY-$paymentId",
                ':edate' => $paymentDate,
                ':sid' => $paymentId,
                ':ref' => $invNum,
                ':narr' => "Supplier Payment ($paymentMethod) for Purchase $invNum"
            ]);

            $accPayable = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '2010'")->fetch()['id'] ?? null;
            $accCash = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1010'")->fetch()['id'] ?? null;
            $accBank = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1020'")->fetch()['id'] ?? null;

            if ($accPayable) {
                $jl1 = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (:id, '0001', :jid, :aid, :dval, 0, 'Supplier Payable Paid', NOW())");
                $jl1->execute([
                    ':id' => generateUuid(),
                    ':jid' => $jeId,
                    ':aid' => $accPayable,
                    ':dval' => $amount
                ]);

                $payAcc = ($paymentMethod === 'Cash' && $accCash) ? $accCash : $accBank;
                if ($payAcc) {
                    $jl2 = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (:id, '0001', :jid, :aid, 0, :cval, 'Cash/Bank Outflow', NOW())");
                    $jl2->execute([
                        ':id' => generateUuid(),
                        ':jid' => $jeId,
                        ':aid' => $payAcc,
                        ':cval' => $amount
                    ]);
                }
            }

            $pdo->commit();

            sendResponse([
                'success' => true,
                'payment_id' => $paymentId,
                'purchase_id' => $purchaseId,
                'supplier_id' => $supplierId,
                'paid_amount' => $newPaidAmount,
                'total_amount' => $totalAmount,
                'remaining' => max(0, round($totalAmount - $newPaidAmount, 2)),
                'status' => $newStatus,
                'supplier_outstanding' => $newOutstanding
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendResponse(['error' => 'Supplier payment transaction failed: ' . $e->getMessage()], 500);
        }
    }

    // ================= CREATE NEW PURCHASE HANDLER =================
    if (empty($input['supplier_id']) || empty($input['items']) || !is_array($input['items'])) {
        sendResponse(['error' => 'Supplier ID and items array are required'], 400);
    }

    if (empty($input['purchase_date']) || $input['purchase_date'] === '0000-00-00') {
        sendResponse(['error' => 'HARD ACCOUNTING REQUIREMENT: Transaction date (purchase_date) is mandatory and cannot be empty.'], 400);
    }
    $supplierId = trim($input['supplier_id']);
    $purchaseDate = trim($input['purchase_date']);
    $dueDate = $input['due_date'] ?? null;
    $paymentMethod = $input['payment_method'] ?? 'Bank Transfer';
    $notes = $input['notes'] ?? null;
    $paidAmount = (float)($input['paid_amount'] ?? 0);
    $items = $input['items'];

    $totalAmount = 0;
    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 0);
        $rate = (float)($item['purchase_rate'] ?? 0);
        if ($qty <= 0 || $rate < 0) {
            sendResponse(['error' => 'Item quantity must be > 0 and rate >= 0'], 400);
        }
        if (isset($item['total']) && (float)$item['total'] > 0) {
            $totalAmount += round((float)$item['total'], 2);
        } else if (isset($item['gst_amount']) && (float)$item['gst_amount'] > 0) {
            $totalAmount += round(($qty * $rate) + (float)$item['gst_amount'], 2);
        } else if (isset($item['gst_percent']) && (float)$item['gst_percent'] > 0) {
            $taxable = $qty * $rate;
            $gstAmt = $taxable * ((float)$item['gst_percent'] / 100);
            $totalAmount += round($taxable + $gstAmt, 2);
        } else {
            $totalAmount += round($qty * $rate, 2);
        }
    }

    if (!empty($input['total_amount']) && (float)$input['total_amount'] > 0) {
        $totalAmount = round((float)$input['total_amount'], 2);
    }

    if ($paidAmount < 0 || $paidAmount > $totalAmount) {
        sendResponse(['error' => 'Paid amount cannot exceed total purchase amount'], 400);
    }

    $status = ($paidAmount >= $totalAmount) ? 'Paid' : (($paidAmount > 0) ? 'Partial' : 'Unpaid');

    // ATOMIC INNODB TRANSACTION
    try {
        $pdo->beginTransaction();

        $counterStmt = $pdo->prepare("SELECT last_number FROM invoice_counter WHERE user_id = '0001' FOR UPDATE");
        $counterStmt->execute();
        $cRow = $counterStmt->fetch();
        $nextNum = ($cRow['last_number'] ?? 0) + 1;

        $maxStmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(invoice_number, 3) AS UNSIGNED)) AS max_num FROM purchases WHERE invoice_number LIKE 'P-%'");
        $maxNum = (int)($maxStmt->fetch()['max_num'] ?? 0);
        if ($nextNum <= $maxNum) {
            $nextNum = $maxNum + 1;
        }

        $upCounter = $pdo->prepare("INSERT INTO invoice_counter (user_id, last_number) VALUES ('0001', :num) ON DUPLICATE KEY UPDATE last_number = VALUES(last_number)");
        $upCounter->execute([':num' => $nextNum]);

        $invoiceNumber = 'P-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
        $purchaseId = generateUuid();

        // Ensure Supplier exists in suppliers table
        $chkSup = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
        $chkSup->execute([$supplierId]);
        if (!$chkSup->fetch()) {
            $supName = !empty($input['supplier_name']) ? trim($input['supplier_name']) : "Supplier $supplierId";
            $insSup = $pdo->prepare("INSERT INTO suppliers (id, user_id, name, type, opening_payable, total_purchases, total_paid, outstanding, created_at) VALUES (:id, '0001', :name, 'Distributor', 0, 0, 0, 0, NOW())");
            $insSup->execute([
                ':id' => $supplierId,
                ':name' => $supName
            ]);
        }

        // 1. Insert Purchase Header
        $pStmt = $pdo->prepare("INSERT INTO purchases (id, user_id, invoice_number, supplier_id, purchase_date, total_amount, paid_amount, payment_method, due_date, notes, status, created_at) VALUES (:id, '0001', :inv, :sup, :pdate, :tot, :paid, :pmeth, :ddate, :notes, :status, NOW())");
        $pStmt->execute([
            ':id' => $purchaseId,
            ':inv' => $invoiceNumber,
            ':sup' => $supplierId,
            ':pdate' => $purchaseDate,
            ':tot' => $totalAmount,
            ':paid' => $paidAmount,
            ':pmeth' => $paymentMethod,
            ':ddate' => $dueDate,
            ':notes' => $notes,
            ':status' => $status
        ]);

        // 2. Insert Items & Update Stock
        foreach ($items as $item) {
            $piId = generateUuid();
            $qty = (float)$item['quantity'];
            $rate = (float)$item['purchase_rate'];
            $itemTotal = isset($item['total']) ? (float)$item['total'] : round($qty * $rate, 2);
            $prodId = $item['product_id'];
            $prodName = !empty($item['product_name']) ? trim($item['product_name']) : (!empty($item['name']) ? trim($item['name']) : "Agro Product $prodId");

            // Ensure Product exists in products table (match by ID, exact name, or fuzzy normalized name)
            $normPName = normalizeAgroProductNamePHP($prodName);
            $chkProd = $pdo->prepare("SELECT id, name FROM products WHERE id = :id OR LOWER(TRIM(name)) = LOWER(TRIM(:pname)) LIMIT 1");
            $chkProd->execute([':id' => $prodId, ':pname' => $prodName]);
            $existingProd = $chkProd->fetch();

            if (!$existingProd && !empty($normPName)) {
                $allProdsStmt = $pdo->query("SELECT id, name FROM products");
                foreach ($allProdsStmt->fetchAll() as $ap) {
                    if (normalizeAgroProductNamePHP($ap['name']) === $normPName) {
                        $existingProd = $ap;
                        break;
                    }
                }
            }
            $itemGstRate = isset($item['gst_percent']) ? (float)$item['gst_percent'] : (isset($item['gst_rate']) ? (float)$item['gst_rate'] : 0);

            $itemHsn = !empty($item['hsn']) ? trim($item['hsn']) : (!empty($item['hsn_code']) ? trim($item['hsn_code']) : null);
            $skuVal = $itemHsn ?: ('SKU-' . rand(1000, 9999));

            if (!$existingProd) {
                $insProd = $pdo->prepare("INSERT INTO products (id, user_id, name, sku, unit, purchase_price, selling_price, gst_rate, opening_stock, current_stock, minimum_stock, stock_status, expiry_status, created_at) VALUES (:id, '0001', :name, :sku, :unit, :price, :selling, :gst, 0, :qty, 5, 'Healthy', 'Valid', NOW())");
                $insProd->execute([
                    ':id' => $prodId,
                    ':name' => $prodName,
                    ':sku' => $skuVal,
                    ':unit' => $item['unit'] ?? 'Bottle',
                    ':price' => $rate,
                    ':selling' => $rate,
                    ':gst' => $itemGstRate,
                    ':qty' => $qty
                ]);
                $targetProdId = $prodId;
            } else {
                $targetProdId = $existingProd['id'];
                // Add Stock & Update Purchase Price & GST Rate if provided
                if ($itemGstRate > 0) {
                    $upProd = $pdo->prepare("UPDATE products SET current_stock = current_stock + :qty, purchase_price = :rate, gst_rate = :gst WHERE id = :pid");
                    $upProd->execute([
                        ':qty' => $qty,
                        ':rate' => $rate,
                        ':gst' => $itemGstRate,
                        ':pid' => $targetProdId
                    ]);
                } else {
                    $upProd = $pdo->prepare("UPDATE products SET current_stock = current_stock + :qty, purchase_price = :rate WHERE id = :pid");
                    $upProd->execute([
                        ':qty' => $qty,
                        ':rate' => $rate,
                        ':pid' => $targetProdId
                    ]);
                }
            }

            $piStmt = $pdo->prepare("INSERT INTO purchase_items (id, user_id, purchase_id, product_id, quantity, unit, purchase_rate, total, created_at) VALUES (:id, '0001', :pur_id, :prod_id, :qty, :unit, :rate, :total, NOW())");
            $piStmt->execute([
                ':id' => $piId,
                ':pur_id' => $purchaseId,
                ':prod_id' => $targetProdId,
                ':qty' => $qty,
                ':unit' => $item['unit'] ?? 'Bag',
                ':rate' => $rate,
                ':total' => $itemTotal
            ]);

            // Update Stock Status
            $upStatus = $pdo->prepare("UPDATE products SET stock_status = IF(current_stock <= 0, 'Out of Stock', IF(current_stock <= minimum_stock, 'Low Stock', 'Healthy')) WHERE id = :pid");
            $upStatus->execute([':pid' => $targetProdId]);

            // Stock Movement IN
            $smStmt = $pdo->prepare("INSERT INTO stock_movements (id, user_id, product_id, type, quantity, balance_after, reference_id, reference_type, notes, created_at) VALUES (:id, '0001', :pid, 'Purchase', :qty, 0, :ref_id, 'purchase', :notes, NOW())");
            $smStmt->execute([
                ':id' => generateUuid(),
                ':pid' => $targetProdId,
                ':qty' => $qty,
                ':ref_id' => $purchaseId,
                ':notes' => "Purchase Invoice $invoiceNumber"
            ]);
        }

        // 3. Update Supplier Balances
        $addPayable = $totalAmount - $paidAmount;
        $upSup = $pdo->prepare("UPDATE suppliers SET total_purchases = total_purchases + :tot, total_paid = total_paid + :paid, outstanding = outstanding + :pay WHERE id = :sid");
        $upSup->execute([
            ':tot' => $totalAmount,
            ':paid' => $paidAmount,
            ':pay' => $addPayable,
            ':sid' => $supplierId
        ]);

        // 4. Insert Purchase Payment if paidAmount > 0
        if ($paidAmount > 0) {
            $ppStmt = $pdo->prepare("INSERT INTO purchase_payments (id, user_id, purchase_id, supplier_id, amount, payment_method, payment_date, notes, created_at) VALUES (:id, '0001', :pur_id, :sup_id, :amt, :pmeth, :pdate, :notes, NOW())");
            $ppStmt->execute([
                ':id' => generateUuid(),
                ':pur_id' => $purchaseId,
                ':sup_id' => $supplierId,
                ':amt' => $paidAmount,
                ':pmeth' => $paymentMethod,
                ':pdate' => $purchaseDate,
                ':notes' => "Payment for Purchase Invoice $invoiceNumber"
            ]);
        }

        // 5. Post Double-Entry Journal Entry
        $jeId = generateUuid();
        $jeStmt = $pdo->prepare("INSERT INTO journal_entries (id, user_id, entry_number, entry_date, source_module, source_id, reference_number, narration, created_at) VALUES (:id, '0001', :enum, :edate, 'Purchases', :sid, :ref, :narr, NOW())");
        $jeStmt->execute([
            ':id' => $jeId,
            ':enum' => "JE-$invoiceNumber",
            ':edate' => $purchaseDate,
            ':sid' => $purchaseId,
            ':ref' => $invoiceNumber,
            ':narr' => "Purchase Invoice $invoiceNumber"
        ]);

        $accStock = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1040'")->fetch()['id'] ?? null;
        $accPayable = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '2010'")->fetch()['id'] ?? null;
        $accCash = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1010'")->fetch()['id'] ?? null;
        $accBank = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1020'")->fetch()['id'] ?? null;

        if ($accStock) {
            $jlStmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (:id, '0001', :jid, :aid, :dval, 0, 'Inventory Stock Inward', NOW())");
            $jlStmt->execute([
                ':id' => generateUuid(),
                ':jid' => $jeId,
                ':aid' => $accStock,
                ':dval' => $totalAmount
            ]);

            if ($paidAmount > 0) {
                $payAcc = ($paymentMethod === 'Cash' && $accCash) ? $accCash : $accBank;
                if ($payAcc) {
                    $jlStmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (:id, '0001', :jid, :aid, 0, :cval, 'Supplier Payment', NOW())");
                    $jlStmt->execute([
                        ':id' => generateUuid(),
                        ':jid' => $jeId,
                        ':aid' => $payAcc,
                        ':cval' => $paidAmount
                    ]);
                }
            }

            if ($addPayable > 0 && $accPayable) {
                $jlStmt = $pdo->prepare("INSERT INTO journal_lines (id, user_id, journal_id, account_id, debit, credit, memo, created_at) VALUES (:id, '0001', :jid, :aid, 0, :cval, 'Supplier Credit Payable', NOW())");
                $jlStmt->execute([
                    ':id' => generateUuid(),
                    ':jid' => $jeId,
                    ':aid' => $accPayable,
                    ':cval' => $addPayable
                ]);
            }
        }

        $pdo->commit();

        sendResponse([
            'success' => true,
            'purchase_id' => $purchaseId,
            'invoice_number' => $invoiceNumber,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'status' => $status
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(['error' => 'Purchase transaction failed: ' . $e->getMessage()], 500);
    }
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) sendResponse(['error' => 'Purchase ID required'], 400);

    try {
        $pdo->beginTransaction();

        $pStmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ?");
        $pStmt->execute([$id]);
        $purchase = $pStmt->fetch();

        if ($purchase) {
            $supplierId = $purchase['supplier_id'];
            $totalAmount = (float)$purchase['total_amount'];
            $paidAmount = (float)$purchase['paid_amount'];
            $addPayable = $totalAmount - $paidAmount;

            $piStmt = $pdo->prepare("SELECT product_id, quantity FROM purchase_items WHERE purchase_id = ?");
            $piStmt->execute([$id]);
            $items = $piStmt->fetchAll();

            foreach ($items as $item) {
                $pid = $item['product_id'];
                $qty = (float)$item['quantity'];

                $upStock = $pdo->prepare("UPDATE products SET current_stock = GREATEST(0, current_stock - :qty) WHERE id = :pid");
                $upStock->execute([':qty' => $qty, ':pid' => $pid]);

                $upStatus = $pdo->prepare("UPDATE products SET stock_status = IF(current_stock <= 0, 'Out of Stock', IF(current_stock <= minimum_stock, 'Low Stock', 'Healthy')) WHERE id = :pid");
                $upStatus->execute([':pid' => $pid]);
            }

            $upSup = $pdo->prepare("UPDATE suppliers SET 
                total_purchases = GREATEST(0, total_purchases - :tot), 
                total_paid = GREATEST(0, total_paid - :paid), 
                outstanding = GREATEST(0, outstanding - :pay) 
                WHERE id = :sid");
            $upSup->execute([
                ':tot' => $totalAmount,
                ':paid' => $paidAmount,
                ':pay' => $addPayable,
                ':sid' => $supplierId
            ]);
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $pdo->exec("DELETE FROM purchase_payments WHERE purchase_id = " . $pdo->quote($id));
        $pdo->exec("DELETE FROM purchase_items WHERE purchase_id = " . $pdo->quote($id));
        $pdo->exec("DELETE FROM journal_entries WHERE source_id = " . $pdo->quote($id));
        $pdo->exec("DELETE FROM stock_movements WHERE reference_id = " . $pdo->quote($id));
        $pdo->exec("DELETE FROM purchases WHERE id = " . $pdo->quote($id));
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

        $pdo->commit();
        sendResponse(['success' => true, 'message' => 'Purchase invoice deleted, product stock reversed, supplier ledger updated!']);
    } catch (Exception $e) {
        $pdo->rollBack();
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        sendResponse(['error' => 'Delete purchase failed: ' . $e->getMessage()], 500);
    }
}

