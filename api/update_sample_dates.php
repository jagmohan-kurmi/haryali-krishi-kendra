<?php
require_once __DIR__ . '/config.php';
$pdo = getDatabaseConnection();

// Update Hybrid Maize Seeds with batch, mfd and exp
$pdo->exec("UPDATE products SET batch_number = 'HMS-26-09', mfd_date = '2026-02-15', expiry_date = '2028-02-14' WHERE name LIKE '%Maize%' AND (batch_number IS NULL OR batch_number = '')");

// Update other common products if null
$pdo->exec("UPDATE products SET batch_number = 'NPK-26-01', mfd_date = '2026-01-10', expiry_date = '2028-01-09' WHERE name LIKE '%NPK%' AND (batch_number IS NULL OR batch_number = '')");
$pdo->exec("UPDATE products SET batch_number = 'BAY-26-44', mfd_date = '2026-03-01', expiry_date = '2028-02-28' WHERE name LIKE '%Confidor%' AND (mfd_date IS NULL)");
$pdo->exec("UPDATE products SET batch_number = 'DRP-26-12', mfd_date = '2025-12-01', expiry_date = '2030-12-01' WHERE name LIKE '%Drip%' AND (mfd_date IS NULL)");

echo json_encode(['success' => true, 'message' => 'Updated sample product batch, mfd, and exp dates!']);
