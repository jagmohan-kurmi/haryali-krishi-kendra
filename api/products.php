<?php
require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'];

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

if ($method === 'GET') {
    if (isset($_GET['action']) && $_GET['action'] === 'reset_zero') {
        $pdo->exec("UPDATE products SET opening_stock = 0, current_stock = 0, stock_status = 'Out of Stock'");
        $pdo->exec("DELETE FROM stock_movements");
        sendResponse(['success' => true, 'message' => 'All product inventory stocks set to ZERO (0)!']);
    }

    if (isset($_GET['action']) && $_GET['action'] === 'merge_duplicates') {
        try {
            $pdo->beginTransaction();
            $allStmt = $pdo->query("SELECT * FROM products ORDER BY current_stock DESC, created_at ASC");
            $allProducts = $allStmt->fetchAll();

            $grouped = [];
            foreach ($allProducts as $p) {
                $normKey = normalizeAgroProductNamePHP($p['name']);
                if (empty($normKey)) continue;
                if (!isset($grouped[$normKey])) {
                    $grouped[$normKey] = [];
                }
                $grouped[$normKey][] = $p;
            }

            $mergedCount = 0;

            foreach ($grouped as $normKey => $prods) {
                if (count($prods) <= 1) continue;

                // Canonical product is the one with highest current_stock
                usort($prods, function($a, $b) {
                    return (float)$b['current_stock'] <=> (float)$a['current_stock'];
                });

                $canonical = $prods[0];
                $canonicalId = $canonical['id'];
                $totalCurrentStock = 0;
                $totalOpeningStock = 0;
                $otherIds = [];

                foreach ($prods as $p) {
                    $totalCurrentStock += (float)($p['current_stock'] ?? 0);
                    $totalOpeningStock += (float)($p['opening_stock'] ?? 0);
                    if ($p['id'] !== $canonicalId) {
                        $otherIds[] = $p['id'];
                    }
                }

                if (!empty($otherIds)) {
                    $idList = "'" . implode("','", array_map('addslashes', $otherIds)) . "'";
                    
                    $pdo->exec("UPDATE sale_items SET product_id = '$canonicalId' WHERE product_id IN ($idList)");
                    $pdo->exec("UPDATE purchase_items SET product_id = '$canonicalId' WHERE product_id IN ($idList)");
                    $pdo->exec("UPDATE stock_movements SET product_id = '$canonicalId' WHERE product_id IN ($idList)");
                    if ($pdo->query("SHOW TABLES LIKE 'customer_return_items'")->rowCount() > 0) {
                        $pdo->exec("UPDATE customer_return_items SET product_id = '$canonicalId' WHERE product_id IN ($idList)");
                    }
                    if ($pdo->query("SHOW TABLES LIKE 'supplier_return_items'")->rowCount() > 0) {
                        $pdo->exec("UPDATE supplier_return_items SET product_id = '$canonicalId' WHERE product_id IN ($idList)");
                    }

                    $pdo->exec("DELETE FROM products WHERE id IN ($idList)");

                    $minStock = (float)($canonical['minimum_stock'] ?? 10);
                    $newStatus = ($totalCurrentStock <= 0) ? 'Out of Stock' : (($totalCurrentStock <= $minStock) ? 'Low Stock' : 'Healthy');
                    $upCanon = $pdo->prepare("UPDATE products SET opening_stock = :op, current_stock = :cur, stock_status = :st WHERE id = :id");
                    $upCanon->execute([
                        ':op' => $totalOpeningStock,
                        ':cur' => $totalCurrentStock,
                        ':st' => $newStatus,
                        ':id' => $canonicalId
                    ]);
                    $mergedCount += count($otherIds);
                }
            }

            $pdo->commit();
            sendResponse(['success' => true, 'merged_count' => $mergedCount, 'message' => "Successfully merged $mergedCount duplicate products!"]);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendResponse(['error' => 'Merge failed: ' . $e->getMessage()], 500);
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM products ORDER BY current_stock DESC, name ASC");
    $stmt->execute();
    $products = $stmt->fetchAll();
    sendResponse($products);
}

if ($method === 'POST') {
    $input = getJsonInput();
    if (empty($input['name']) || empty($input['unit'])) {
        sendResponse(['error' => 'Product name and unit are required'], 400);
    }

    $id = $input['id'] ?? generateUuid();
    $userId = $input['user_id'] ?? '0001';
    $name = trim($input['name']);
    $sku = $input['sku'] ?? ('SKU-' . strtoupper(substr(md5($name), 0, 6)));
    $category = $input['category'] ?? 'Fertilizers';
    $brand = $input['brand'] ?? null;
    $company = $input['company'] ?? null;
    $unit = $input['unit'];
    $purchasePrice = (float)($input['purchase_price'] ?? 0);
    $sellingPrice = (float)($input['selling_price'] ?? 0);
    $openingStock = (float)($input['opening_stock'] ?? 0);
    $minimumStock = (float)($input['minimum_stock'] ?? 10);
    $batchNumber = $input['batch_number'] ?? null;
    $mfdDate = $input['mfd_date'] ?? null;
    $expiryDate = $input['expiry_date'] ?? null;
    $licenseNumber = $input['license_number'] ?? null;
    $description = $input['description'] ?? null;
    $gstRate = (float)($input['gst_rate'] ?? 0);

    // Prevent duplicate product creation by fuzzy/normalized name
    $normInputName = normalizeAgroProductNamePHP($name);
    $allProds = $pdo->query("SELECT * FROM products")->fetchAll();
    $existing = null;
    foreach ($allProds as $p) {
        if (normalizeAgroProductNamePHP($p['name']) === $normInputName) {
            $existing = $p;
            break;
        }
    }

    if ($existing) {
        $existingId = $existing['id'];
        $newStock = (float)$existing['current_stock'] + $openingStock;
        $minStock = (float)($existing['minimum_stock'] ?? 10);
        $stockStatus = ($newStock <= 0) ? 'Out of Stock' : (($newStock <= $minStock) ? 'Low Stock' : 'Healthy');

        $up = $pdo->prepare("UPDATE products SET current_stock = :cur, purchase_price = GREATEST(purchase_price, :pp), selling_price = GREATEST(selling_price, :sp), stock_status = :st WHERE id = :id");
        $up->execute([
            ':cur' => $newStock,
            ':pp' => $purchasePrice,
            ':sp' => $sellingPrice,
            ':st' => $stockStatus,
            ':id' => $existingId
        ]);

        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$existingId]);
        sendResponse($stmt->fetch());
    }

    $stockStatus = ($openingStock <= 0) ? 'Out of Stock' : (($openingStock <= $minimumStock) ? 'Low Stock' : 'Healthy');

    $stmt = $pdo->prepare("INSERT INTO products (id, user_id, name, sku, category, brand, company, unit, purchase_price, selling_price, opening_stock, current_stock, minimum_stock, batch_number, mfd_date, expiry_date, license_number, description, gst_rate, stock_status, expiry_status, created_at) VALUES (:id, :user_id, :name, :sku, :category, :brand, :company, :unit, :purchase_price, :selling_price, :opening_stock, :current_stock, :minimum_stock, :batch_number, :mfd_date, :expiry_date, :license_number, :description, :gst_rate, :stock_status, 'Valid', NOW())");
    $stmt->execute([
        ':id' => $id,
        ':user_id' => $userId,
        ':name' => $name,
        ':sku' => $sku,
        ':category' => $category,
        ':brand' => $brand,
        ':company' => $company,
        ':unit' => $unit,
        ':purchase_price' => $purchasePrice,
        ':selling_price' => $sellingPrice,
        ':opening_stock' => $openingStock,
        ':current_stock' => $openingStock,
        ':minimum_stock' => $minimumStock,
        ':batch_number' => $batchNumber,
        ':mfd_date' => $mfdDate,
        ':expiry_date' => $expiryDate,
        ':license_number' => $licenseNumber,
        ':description' => $description,
        ':gst_rate' => $gstRate,
        ':stock_status' => $stockStatus
    ]);

    // Insert Opening Stock Movement
    $smStmt = $pdo->prepare("INSERT INTO stock_movements (id, user_id, product_id, type, quantity, balance_after, reference_type, notes, created_at) VALUES (?, '0001', ?, 'Opening', ?, ?, 'opening', 'Initial Stock Entry', NOW())");
    $smStmt->execute([generateUuid(), $id, $openingStock, $openingStock]);

    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
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
        sendResponse(['error' => 'Product ID required for update'], 400);
    }

    $fields = [];
    $params = [':id' => $id];

    $allowedCols = ['name', 'sku', 'category', 'brand', 'company', 'unit', 'purchase_price', 'selling_price', 'opening_stock', 'current_stock', 'stock_status', 'minimum_stock', 'batch_number', 'mfd_date', 'expiry_date', 'description', 'gst_rate'];

    foreach ($allowedCols as $col) {
        if (array_key_exists($col, $input)) {
            $fields[] = "`$col` = :$col";
            $params[":$col"] = $input[$col];
        }
    }

    if (!empty($fields)) {
        $sql = "UPDATE products SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    sendResponse($stmt->fetch());
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) sendResponse(['error' => 'Product ID required'], 400);

    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        if ($id === 'all' || $id === 'purge') {
            $pdo->exec("TRUNCATE TABLE stock_movements;");
            $pdo->exec("TRUNCATE TABLE products;");
        } else {
            $pdo->exec("DELETE FROM stock_movements WHERE product_id = " . $pdo->quote($id));
            $pdo->exec("DELETE FROM sale_items WHERE product_id = " . $pdo->quote($id));
            $pdo->exec("DELETE FROM purchase_items WHERE product_id = " . $pdo->quote($id));
            if ($pdo->query("SHOW TABLES LIKE 'customer_return_items'")->rowCount() > 0) {
                $pdo->exec("DELETE FROM customer_return_items WHERE product_id = " . $pdo->quote($id));
            }
            if ($pdo->query("SHOW TABLES LIKE 'supplier_return_items'")->rowCount() > 0) {
                $pdo->exec("DELETE FROM supplier_return_items WHERE product_id = " . $pdo->quote($id));
            }
            $pdo->exec("DELETE FROM products WHERE id = " . $pdo->quote($id));
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        sendResponse(['success' => true, 'deleted_id' => $id]);
    } catch (Exception $e) {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        sendResponse(['error' => 'Failed to delete product: ' . $e->getMessage()], 500);
    }
}
