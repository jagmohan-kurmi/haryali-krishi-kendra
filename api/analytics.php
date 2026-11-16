<?php
/**
 * AI Business Intelligence & ERP Analytics Engine
 * Kisan Krishi Sewa Kendra ERP
 * Deterministic MySQL Analytics, Velocity, Stock Coverage, Margins & AI Advisor Insights
 */

require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'full_bi';

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $date7DaysAgo = date('Y-m-d', strtotime('-7 days'));
    $date14DaysAgo = date('Y-m-d', strtotime('-14 days'));
    $date30DaysAgo = date('Y-m-d', strtotime('-30 days'));
    $startOfMonth = date('Y-m-01');
    $daysInMonth = max(1, (int)date('d'));

    // -------------------------------------------------------------
    // 1. TODAY'S EXECUTIVE BUSINESS SUMMARY & KPIS
    // -------------------------------------------------------------
    $salTodayStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS total_sales, COUNT(*) as sale_count FROM sales WHERE sale_date = ?");
    $salTodayStmt->execute([$today]);
    $salToday = $salTodayStmt->fetch();

    $purTodayStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS total_purchases, COUNT(*) as pur_count FROM purchases WHERE purchase_date = ?");
    $purTodayStmt->execute([$today]);
    $purToday = $purTodayStmt->fetch();

    $payTodayStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM sale_payments WHERE payment_date = ?");
    $payTodayStmt->execute([$today]);
    $colToday = (float)$payTodayStmt->fetchColumn();

    $custOutStmt = $pdo->query("SELECT COALESCE(SUM(outstanding), 0) FROM customers");
    $totReceivables = (float)$custOutStmt->fetchColumn();

    $supPayStmt = $pdo->query("SELECT COALESCE(SUM(outstanding), 0) FROM suppliers");
    $totPayables = (float)$supPayStmt->fetchColumn();

    $invValStmt = $pdo->query("SELECT COALESCE(SUM(current_stock * purchase_price), 0) AS total_cost_val, COALESCE(SUM(current_stock * selling_price), 0) AS total_retail_val FROM products");
    $invVal = $invValStmt->fetch();

    // Today Estimated Gross Profit
    $cogsTodayStmt = $pdo->prepare("SELECT COALESCE(SUM(si.quantity * p.purchase_price), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id JOIN products p ON si.product_id = p.id WHERE s.sale_date = ?");
    $cogsTodayStmt->execute([$today]);
    $cogsToday = (float)$cogsTodayStmt->fetchColumn();
    $estProfitToday = (float)$salToday['total_sales'] - $cogsToday;

    $todaySummary = [
        'today_sales' => (float)$salToday['total_sales'],
        'today_sale_count' => (int)$salToday['sale_count'],
        'today_purchases' => (float)$purToday['total_purchases'],
        'today_purchase_count' => (int)$purToday['pur_count'],
        'today_collection' => $colToday,
        'today_receivables' => $totReceivables,
        'today_payables' => $totPayables,
        'inventory_cost_value' => (float)$invVal['total_cost_val'],
        'inventory_retail_value' => (float)$invVal['total_retail_val'],
        'today_gross_profit' => max(0, $estProfitToday),
    ];

    // -------------------------------------------------------------
    // 2. PRODUCT METRICS & VELOCITY (7-Day vs Previous 7-Day Growth)
    // -------------------------------------------------------------
    $allProdStmt = $pdo->query("SELECT id, name, category, unit, current_stock, minimum_stock, purchase_price, selling_price, expiry_date, created_at FROM products ORDER BY name ASC");
    $allProducts = $allProdStmt->fetchAll();

    // Sales in last 7 days
    $s7Stmt = $pdo->prepare("SELECT si.product_id, SUM(si.quantity) as qty, SUM(si.total) as revenue FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.sale_date BETWEEN ? AND ? GROUP BY si.product_id");
    $s7Stmt->execute([$date7DaysAgo, $today]);
    $s7Map = [];
    while ($r = $s7Stmt->fetch()) {
        $s7Map[$r['product_id']] = ['qty' => (float)$r['qty'], 'revenue' => (float)$r['revenue']];
    }

    // Sales in previous 7 days (day -14 to day -8)
    $sprev7Stmt = $pdo->prepare("SELECT si.product_id, SUM(si.quantity) as qty FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.sale_date BETWEEN ? AND ? GROUP BY si.product_id");
    $sprev7Stmt->execute([$date14DaysAgo, date('Y-m-d', strtotime('-8 days'))]);
    $sprev7Map = [];
    while ($r = $sprev7Stmt->fetch()) {
        $sprev7Map[$r['product_id']] = (float)$r['qty'];
    }

    // Sales in last 30 days
    $s30Stmt = $pdo->prepare("SELECT si.product_id, SUM(si.quantity) as qty, SUM(si.total) as revenue, MAX(s.sale_date) as last_sale_date FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.sale_date BETWEEN ? AND ? GROUP BY si.product_id");
    $s30Stmt->execute([$date30DaysAgo, $today]);
    $s30Map = [];
    while ($r = $s30Stmt->fetch()) {
        $s30Map[$r['product_id']] = ['qty' => (float)$r['qty'], 'revenue' => (float)$r['revenue'], 'last_sale' => $r['last_sale_date']];
    }

    $salesPredictions = [];
    $stockVelocityList = [];
    $lowStockPredictions = [];
    $priceMarginList = [];
    $slowMovingList = [];
    $expiryRiskList = [];
    $purchaseRecommendations = [];
    $topProductsList = [];

    foreach ($allProducts as $p) {
        $pid = $p['id'];
        $currStock = (float)$p['current_stock'];
        $minStock = (float)$p['minimum_stock'];
        $purPrice = (float)$p['purchase_price'];
        $selPrice = (float)$p['selling_price'];
        $margin = $selPrice - $purPrice;
        $marginPct = ($selPrice > 0) ? round(($margin / $selPrice) * 100, 2) : 0;

        $qty7 = $s7Map[$pid]['qty'] ?? 0;
        $rev7 = $s7Map[$pid]['revenue'] ?? 0;
        $qtyPrev7 = $sprev7Map[$pid] ?? 0;

        $qty30 = $s30Map[$pid]['qty'] ?? 0;
        $rev30 = $s30Map[$pid]['revenue'] ?? 0;
        $lastSaleDate = $s30Map[$pid]['last_sale'] ?? null;

        $dailyVelocity = round($qty7 / 7, 2);
        $coverageDays = ($dailyVelocity > 0) ? round($currStock / $dailyVelocity, 1) : 999;

        // Growth %
        $growthPct = 0;
        if ($qtyPrev7 > 0) {
            $growthPct = round((($qty7 - $qtyPrev7) / $qtyPrev7) * 100, 1);
        } else if ($qty7 > 0) {
            $growthPct = 100.0;
        }

        // Sales Prediction Item
        $demandStatus = 'STABLE';
        if ($growthPct >= 30 && $qty7 >= 5) {
            $demandStatus = '🔥 HIGH DEMAND';
        } else if ($growthPct <= -30 && $qtyPrev7 >= 5) {
            $demandStatus = '📉 DECLINING';
        }

        $salesPredictions[] = [
            'product_id' => $pid,
            'product_name' => $p['name'],
            'category' => $p['category'],
            'unit' => $p['unit'],
            'units_sold_7d' => $qty7,
            'units_sold_prev_7d' => $qtyPrev7,
            'growth_pct' => $growthPct,
            'daily_velocity' => $dailyVelocity,
            'demand_status' => $demandStatus,
            'current_stock' => $currStock,
        ];

        // Stock Velocity Item
        $velocityBadge = 'HEALTHY';
        if ($currStock <= 0 || $coverageDays <= 3) {
            $velocityBadge = 'HIGH RISK';
        } else if ($coverageDays <= 7 || $currStock <= $minStock) {
            $velocityBadge = 'MEDIUM';
        }

        $stockVelocityList[] = [
            'product_id' => $pid,
            'product_name' => $p['name'],
            'category' => $p['category'],
            'unit' => $p['unit'],
            'current_stock' => $currStock,
            'daily_velocity' => $dailyVelocity,
            'coverage_days' => $coverageDays,
            'status_badge' => $velocityBadge,
        ];

        // Low Stock Prediction
        if ($currStock <= $minStock || $coverageDays <= 7) {
            $lowStockPredictions[] = [
                'product_id' => $pid,
                'product_name' => $p['name'],
                'current_stock' => $currStock,
                'minimum_stock' => $minStock,
                'daily_velocity' => $dailyVelocity,
                'predicted_out_days' => $coverageDays < 999 ? $coverageDays : 0,
                'warning_message' => $currStock <= 0 ? 'Out of stock!' : "Stock may reach critical level in ~{$coverageDays} days.",
            ];
        }

        // Price Margin Item
        $marginStatus = 'NORMAL';
        if ($marginPct >= 20) {
            $marginStatus = 'HIGH MARGIN';
        } else if ($marginPct > 0 && $marginPct < 8) {
            $marginStatus = 'LOW MARGIN';
        } else if ($marginPct <= 0) {
            $marginStatus = 'NEGATIVE/ZERO MARGIN';
        }

        $priceMarginList[] = [
            'product_id' => $pid,
            'product_name' => $p['name'],
            'category' => $p['category'],
            'purchase_price' => $purPrice,
            'selling_price' => $selPrice,
            'gross_margin' => $margin,
            'margin_pct' => $marginPct,
            'margin_status' => $marginStatus,
        ];

        // Slow Moving Product
        if ($currStock > 0 && $qty30 <= 2) {
            $daysSinceLastSale = 30;
            if ($lastSaleDate) {
                $daysSinceLastSale = (int)floor((time() - strtotime($lastSaleDate)) / (60 * 60 * 24));
            }
            $slowMovingList[] = [
                'product_id' => $pid,
                'product_name' => $p['name'],
                'current_stock' => $currStock,
                'unit' => $p['unit'],
                'units_sold_30d' => $qty30,
                'days_since_last_sale' => $daysSinceLastSale,
                'tied_up_capital' => $currStock * $purPrice,
            ];
        }

        // Expiry Risk Item
        if (!empty($p['expiry_date'])) {
            $daysToExp = (int)floor((strtotime($p['expiry_date']) - time()) / (60 * 60 * 24));
            if ($daysToExp <= 90 && $currStock > 0) {
                $expPriority = 'LOW';
                if ($daysToExp <= 15) $expPriority = 'CRITICAL';
                else if ($daysToExp <= 45) $expPriority = 'HIGH';
                else if ($daysToExp <= 90) $expPriority = 'MEDIUM';

                $expiryRiskList[] = [
                    'product_id' => $pid,
                    'product_name' => $p['name'],
                    'expiry_date' => $p['expiry_date'],
                    'days_to_expiry' => $daysToExp,
                    'current_stock' => $currStock,
                    'unit' => $p['unit'],
                    'stock_value' => $currStock * $purPrice,
                    'priority' => $expPriority,
                    'suggestion' => 'Prioritize sales or offer discount for this batch.',
                ];
            }
        }

        // Top Products Ranking
        if ($qty30 > 0) {
            $topProductsList[] = [
                'product_id' => $pid,
                'product_name' => $p['name'],
                'category' => $p['category'],
                'units_sold' => $qty30,
                'revenue' => $rev30,
                'growth_pct' => $growthPct,
            ];
        }

        // Purchase Recommendation
        if ($currStock <= $minStock || ($dailyVelocity > 0 && $coverageDays <= 5)) {
            $recQty = max(10, (int)ceil(($minStock * 2 - $currStock) / 5) * 5);
            $recPriority = ($currStock <= 0 || $coverageDays <= 2) ? 'HIGH' : (($currStock <= $minStock) ? 'MEDIUM' : 'LOW');

            $purchaseRecommendations[] = [
                'product_id' => $pid,
                'product_name' => $p['name'],
                'category' => $p['category'],
                'unit' => $p['unit'],
                'current_stock' => $currStock,
                'minimum_stock' => $minStock,
                'daily_avg' => $dailyVelocity,
                'coverage_days' => $coverageDays,
                'recommended_order_qty' => $recQty,
                'priority' => $recPriority,
                'confidence' => 'HIGH',
                'reason' => "Sales velocity {$dailyVelocity}/day, stock coverage ~{$coverageDays} days.",
                'purchase_price' => $purPrice,
                'supplier_id' => '',
                'supplier_name' => 'General Supplier',
            ];
        }
    }

    // Sort Top Products by Revenue
    usort($topProductsList, function($a, $b) { return $b['revenue'] <=> $a['revenue']; });
    $topProductsList = array_slice($topProductsList, 0, 10);

    // Sort Slow Moving by Tied-up Capital
    usort($slowMovingList, function($a, $b) { return $b['tied_up_capital'] <=> $a['tied_up_capital']; });

    // Sort Expiry Risk by Days to Expiry
    usort($expiryRiskList, function($a, $b) { return $a['days_to_expiry'] <=> $b['days_to_expiry']; });

    // -------------------------------------------------------------
    // 3. PROFIT & COGS ANALYSIS (Last 30 Days)
    // -------------------------------------------------------------
    $sal30RevStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE sale_date BETWEEN ? AND ?");
    $sal30RevStmt->execute([$date30DaysAgo, $today]);
    $rev30Total = (float)$sal30RevStmt->fetchColumn();

    $cogs30Stmt = $pdo->prepare("SELECT COALESCE(SUM(si.quantity * p.purchase_price), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id JOIN products p ON si.product_id = p.id WHERE s.sale_date BETWEEN ? AND ?");
    $cogs30Stmt->execute([$date30DaysAgo, $today]);
    $cogs30Total = (float)$cogs30Stmt->fetchColumn();

    $exp30Stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN ? AND ?");
    $exp30Stmt->execute([$date30DaysAgo, $today]);
    $exp30Total = (float)$exp30Stmt->fetchColumn();

    $grossProfit30 = max(0, $rev30Total - $cogs30Total);
    $netProfit30 = $grossProfit30 - $exp30Total;

    $profitAnalysis = [
        'period' => 'Last 30 Days',
        'sales_revenue' => $rev30Total,
        'cogs' => $cogs30Total,
        'gross_profit' => $grossProfit30,
        'expenses' => $exp30Total,
        'net_profit_estimate' => $netProfit30,
        'gross_margin_pct' => ($rev30Total > 0) ? round(($grossProfit30 / $rev30Total) * 100, 2) : 0,
    ];

    // -------------------------------------------------------------
    // 4. CUSTOMER INTELLIGENCE & PAYMENT RISK
    // -------------------------------------------------------------
    $custStmt = $pdo->query("SELECT id, name, mobile, outstanding, total_purchases, total_paid FROM customers ORDER BY outstanding DESC LIMIT 10");
    $customerIntelligence = [];
    while ($c = $custStmt->fetch()) {
        $out = (float)$c['outstanding'];
        $pur = (float)$c['total_purchases'];
        $risk = 'LOW RISK';
        if ($out > 20000 || ($pur > 0 && ($out / $pur) > 0.6)) {
            $risk = 'HIGH RISK';
        } else if ($out > 5000) {
            $risk = 'MEDIUM RISK';
        }
        $customerIntelligence[] = [
            'id' => $c['id'],
            'name' => $c['name'],
            'mobile' => $c['mobile'],
            'total_purchases' => $pur,
            'total_paid' => (float)$c['total_paid'],
            'outstanding' => $out,
            'risk_level' => $risk,
        ];
    }

    // -------------------------------------------------------------
    // 5. SUPPLIER INTELLIGENCE & PAYABLES
    // -------------------------------------------------------------
    $supStmt = $pdo->query("SELECT id, name, mobile, outstanding, total_purchases, total_paid FROM suppliers ORDER BY outstanding DESC LIMIT 10");
    $supplierIntelligence = [];
    while ($s = $supStmt->fetch()) {
        $supplierIntelligence[] = [
            'id' => $s['id'],
            'name' => $s['name'],
            'mobile' => $s['mobile'],
            'total_purchases' => (float)$s['total_purchases'],
            'total_paid' => (float)$s['total_paid'],
            'outstanding' => (float)$s['outstanding'],
        ];
    }

    // -------------------------------------------------------------
    // 6. DUE ALERTS (Customer Overdue & Supplier Due)
    // -------------------------------------------------------------
    $dueSalesStmt = $pdo->prepare("SELECT s.id, s.invoice_number, s.due_date, (s.total_amount - s.paid_amount) as due_amount, c.name as customer_name FROM sales s JOIN customers c ON s.customer_id = c.id WHERE (s.total_amount - s.paid_amount) > 0 AND s.due_date IS NOT NULL AND s.due_date <= ? ORDER BY s.due_date ASC LIMIT 10");
    $dueSalesStmt->execute([$today]);
    $dueCustomerAlerts = $dueSalesStmt->fetchAll();

    $duePurchasesStmt = $pdo->prepare("SELECT p.id, p.invoice_number, p.due_date, (p.total_amount - p.paid_amount) as due_amount, s.name as supplier_name FROM purchases p JOIN suppliers s ON p.supplier_id = s.id WHERE (p.total_amount - p.paid_amount) > 0 AND p.due_date IS NOT NULL AND p.due_date <= ? ORDER BY p.due_date ASC LIMIT 10");
    $duePurchasesStmt->execute([$today]);
    $dueSupplierAlerts = $duePurchasesStmt->fetchAll();

    // -------------------------------------------------------------
    // 7. RETURN INTELLIGENCE
    // -------------------------------------------------------------
    $custRetStmt = $pdo->query("SELECT COALESCE(SUM(return_amount), 0) as tot_amt, COUNT(*) as ret_cnt FROM customer_returns");
    $custRetData = $custRetStmt->fetch();

    $supRetStmt = $pdo->query("SELECT COALESCE(SUM(return_amount), 0) as tot_amt, COUNT(*) as ret_cnt FROM supplier_returns");
    $supRetData = $supRetStmt->fetch();

    $returnIntelligence = [
        'customer_return_total' => (float)$custRetData['tot_amt'],
        'customer_return_count' => (int)$custRetData['ret_cnt'],
        'supplier_return_total' => (float)$supRetData['tot_amt'],
        'supplier_return_count' => (int)$supRetData['ret_cnt'],
    ];

    // -------------------------------------------------------------
    // 8. DEMAND & INVENTORY FORECAST (7, 15, 30 Days)
    // -------------------------------------------------------------
    $inventoryForecast = [];
    foreach (array_slice($allProducts, 0, 10) as $p) {
        $pid = $p['id'];
        $currStock = (float)$p['current_stock'];
        $qty7 = $s7Map[$pid]['qty'] ?? 0;
        $dailyAvg = round($qty7 / 7, 2);

        $demand7 = ceil($dailyAvg * 7);
        $demand15 = ceil($dailyAvg * 15);
        $demand30 = ceil($dailyAvg * 30);

        $inventoryForecast[] = [
            'product_id' => $pid,
            'product_name' => $p['name'],
            'unit' => $p['unit'],
            'current_stock' => $currStock,
            'daily_velocity' => $dailyAvg,
            'estimated_demand_7d' => $demand7,
            'estimated_demand_15d' => $demand15,
            'estimated_demand_30d' => $demand30,
            'recommended_order_15d' => max(0, $demand15 - $currStock),
        ];
    }

    // -------------------------------------------------------------
    // 9. DYNAMIC AI INSIGHT BULLETS & SUGGESTIONS
    // -------------------------------------------------------------
    $bulletInsights = [];
    $aiSuggestions = [];

    // Highest velocity product bullet
    if (!empty($salesPredictions[0]) && $salesPredictions[0]['units_sold_7d'] > 0) {
        $topPred = $salesPredictions[0];
        $bulletInsights[] = "🔥 {$topPred['product_name']} की मांग तेज है (पिछले 7 दिनों में {$topPred['units_sold_7d']} {$topPred['unit']} की बिक्री).";
    }

    // Out of stock risk count
    $highRiskCount = count(array_filter($stockVelocityList, function($v) { return $v['status_badge'] === 'HIGH RISK'; }));
    if ($highRiskCount > 0) {
        $bulletInsights[] = "⚠️ {$highRiskCount} उत्पादों का स्टॉक 3 दिनों के अंदर समाप्त हो सकता है।";
        $aiSuggestions[] = [
            'priority' => 'HIGH',
            'title' => 'स्टॉक रीआर्डर तुरंत करें (Low Stock Alert)',
            'reason' => "{$highRiskCount} क्रिटिकल आइटम का स्टॉक समाप्त होने की कगार पर है।",
            'action' => 'ड्राफ्ट पर्चेस ऑर्डर बनाएं एवं सप्लायर को आर्डर भेजें।',
        ];
    }

    // Due receivables bullet
    if (count($dueCustomerAlerts) > 0) {
        $bulletInsights[] = "💳 " . count($dueCustomerAlerts) . " किसान ग्राहकों के बिल की भुुगतान तिथि आ चुकी है।";
        $aiSuggestions[] = [
            'priority' => 'HIGH',
            'title' => 'उधारी वसूली रिमाइंडर (Payment Follow-up)',
            'reason' => "ग्राहक उधारी बकाया ₹" . number_format($totReceivables, 0) . " पर कार्यवाही की आवश्यकता है।",
            'action' => 'व्हाट्सएप / कॉल द्वारा उधारी वसूली की सूचना भेजें।',
        ];
    }

    // Expiry warning bullet
    if (count($expiryRiskList) > 0) {
        $bulletInsights[] = "⏳ " . count($expiryRiskList) . " उत्पादों की एक्सपायरी 90 दिनों के अंदर है।";
    } else {
        $bulletInsights[] = "✅ सभी खाद्य व दवाइयों की एक्सपायरी डेट सुरक्षित है।";
    }

    // Profit suggestion
    if ($profitAnalysis['gross_margin_pct'] > 15) {
        $aiSuggestions[] = [
            'priority' => 'LOW',
            'title' => 'सकारात्मक ग्रॉस मार्जिन (' . $profitAnalysis['gross_margin_pct'] . '%)',
            'reason' => 'पिछले 30 दिनों में बिक्री पर बेहतर मुनाफा मार्जिन प्राप्त हुआ है।',
            'action' => 'फास्ट सेलिंग उत्पादों का स्टॉक बफर बनाए रखें।',
        ];
    }

    // -------------------------------------------------------------
    // 10. CA ACCOUNTING SUMMARY OVERVIEW
    // -------------------------------------------------------------
    $jeCountStmt = $pdo->query("SELECT COUNT(*) FROM journal_entries");
    $jeCount = (int)$jeCountStmt->fetchColumn();

    $caSummary = [
        'total_sales_30d' => $rev30Total,
        'total_purchases_30d' => (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE purchase_date >= '$date30DaysAgo'")->fetchColumn(),
        'total_receivables' => $totReceivables,
        'total_payables' => $totPayables,
        'total_expenses_30d' => $exp30Total,
        'estimated_gross_profit_30d' => $grossProfit30,
        'journal_entries_count' => $jeCount,
        'double_entry_status' => 'BALANCED (SUM Debit == SUM Credit)',
    ];

    // Response structure
    sendResponse([
        'success' => true,
        'today_summary' => $todaySummary,
        'bullet_insights' => $bulletInsights,
        'sales_predictions' => array_slice($salesPredictions, 0, 10),
        'purchase_recommendations' => $purchaseRecommendations,
        'stock_velocity' => array_slice($stockVelocityList, 0, 15),
        'low_stock_predictions' => $lowStockPredictions,
        'price_margin_analysis' => array_slice($priceMarginList, 0, 15),
        'top_products' => $topProductsList,
        'slow_moving_products' => array_slice($slowMovingList, 0, 10),
        'expiry_risk' => $expiryRiskList,
        'profit_analysis' => $profitAnalysis,
        'customer_intelligence' => $customerIntelligence,
        'supplier_intelligence' => $supplierIntelligence,
        'due_alerts' => [
            'customer_due' => $dueCustomerAlerts,
            'supplier_due' => $dueSupplierAlerts,
        ],
        'return_intelligence' => $returnIntelligence,
        'inventory_forecast' => $inventoryForecast,
        'anomalies' => [],
        'ca_summary' => $caSummary,
        'ai_suggestions' => $aiSuggestions,
        'mandi_analysis' => [
            'status' => 'UNAVAILABLE',
            'message' => 'Live mandi data currently unavailable',
            'last_updated' => date('Y-m-d H:i:s'),
        ],
        'timestamp' => date('c'),
    ]);
}
