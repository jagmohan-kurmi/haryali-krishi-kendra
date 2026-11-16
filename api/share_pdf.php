<?php
/**
 * Rajput Krishi Sewa Kendra - PDF Sharing Backend API
 * Uploads generated invoice/challan/purchase PDF and generates shareable WhatsApp link
 */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$uploadDir = __DIR__ . '/shared_docs/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
    // Put index.html in the folder to prevent directory indexing
    @file_put_contents($uploadDir . 'index.html', '<!DOCTYPE html><html><head><title>Access Denied</title></head><body><h1>403 Forbidden</h1></body></html>');
}

// Auto clean files older than 30 days
try {
    $now = time();
    $files = @glob($uploadDir . '*.pdf');
    if ($files) {
        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file) > 30 * 86400)) {
                @unlink($file);
            }
        }
    }
} catch (Exception $e) {
    // Ignore cleanup error
}

if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid PDF file received']);
    exit;
}

$uploadedFile = $_FILES['pdf_file'];
$fileSize = $uploadedFile['size'];

// Limit max size to 15MB
if ($fileSize > 15 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'File size exceeds 15MB limit']);
    exit;
}

$requestedName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $_POST['file_name'] ?? 'document.pdf');
if (!preg_match('/\.pdf$/i', $requestedName)) {
    $requestedName .= '.pdf';
}

// Generate unique hash to avoid collisions
$fileToken = substr(md5(uniqid(mt_rand(), true)), 0, 8);
$finalFileName = pathinfo($requestedName, PATHINFO_FILENAME) . '_' . $fileToken . '.pdf';
$destination = $uploadDir . $finalFileName;

if (!move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save PDF file']);
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'rajpootkrishisewakendra.in';
$shareUrl = $protocol . $host . '/api/view_doc.php?f=' . urlencode($finalFileName);

echo json_encode([
    'success' => true,
    'file_name' => $finalFileName,
    'share_url' => $shareUrl,
    'url' => $shareUrl,
    'created_at' => date('Y-m-d H:i:s')
]);
