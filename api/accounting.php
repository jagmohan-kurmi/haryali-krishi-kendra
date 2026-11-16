<?php
require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();
$action = $_GET['action'] ?? 'chart_of_accounts';

if ($action === 'chart_of_accounts') {
    $stmt = $pdo->prepare("SELECT * FROM chart_of_accounts ORDER BY account_code ASC");
    $stmt->execute();
    sendResponse($stmt->fetchAll());
}

if ($action === 'journal_entries') {
    $stmt = $pdo->prepare("SELECT je.*, jl.id AS line_id, jl.account_id, jl.debit, jl.credit, jl.memo, coa.account_code, coa.account_name FROM journal_entries je LEFT JOIN journal_lines jl ON je.id = jl.journal_id LEFT JOIN chart_of_accounts coa ON jl.account_id = coa.id ORDER BY je.entry_date DESC, je.created_at DESC");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $journals = [];
    foreach ($rows as $r) {
        $jId = $r['id'];
        if (!isset($journals[$jId])) {
            $journals[$jId] = [
                'id' => $r['id'],
                'user_id' => $r['user_id'],
                'entry_number' => $r['entry_number'],
                'entry_date' => $r['entry_date'],
                'source_module' => $r['source_module'],
                'source_id' => $r['source_id'],
                'reference_number' => $r['reference_number'],
                'narration' => $r['narration'],
                'created_at' => $r['created_at'],
                'lines' => [],
                'total_debit' => 0,
                'total_credit' => 0
            ];
        }

        if ($r['line_id']) {
            $debit = (float)$r['debit'];
            $credit = (float)$r['credit'];
            $journals[$jId]['lines'][] = [
                'id' => $r['line_id'],
                'journal_id' => $r['id'],
                'account_id' => $r['account_id'],
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $r['memo'],
                'account' => [
                    'account_code' => $r['account_code'],
                    'account_name' => $r['account_name']
                ]
            ];
            $journals[$jId]['total_debit'] += $debit;
            $journals[$jId]['total_credit'] += $credit;
        }
    }

    sendResponse(array_values($journals));
}

if ($action === 'reports') {
    // Generate Financial Trial Balance & Balance Sheet Checksums
    $debits = (float)$pdo->query("SELECT SUM(debit) AS total FROM journal_lines")->fetch()['total'];
    $credits = (float)$pdo->query("SELECT SUM(credit) AS total FROM journal_lines")->fetch()['total'];

    $salesTotal = (float)$pdo->query("SELECT SUM(total_amount) AS total FROM sales")->fetch()['total'];
    $purchasesTotal = (float)$pdo->query("SELECT SUM(total_amount) AS total FROM purchases")->fetch()['total'];
    $expensesTotal = (float)$pdo->query("SELECT SUM(amount) AS total FROM expenses")->fetch()['total'];
    $inventoryVal = (float)$pdo->query("SELECT SUM(current_stock * purchase_price) AS total FROM products")->fetch()['total'];

    sendResponse([
        'trial_balance' => [
            'total_debit' => $debits,
            'total_credit' => $credits,
            'is_balanced' => ($debits === $credits)
        ],
        'summary' => [
            'total_sales' => $salesTotal,
            'total_purchases' => $purchasesTotal,
            'total_expenses' => $expensesTotal,
            'inventory_valuation' => $inventoryVal,
            'net_profit' => round($salesTotal - $purchasesTotal - $expensesTotal, 2)
        ]
    ]);
}
