<?php
/**
 * Kisan Krishi Sewa Kendra - PDF Viewer Endpoint
 * Safely streams PDF documents inline to mobile and web browsers
 */

$fileName = $_GET['f'] ?? '';
$fileName = basename($fileName); // Prevent directory traversal

if (empty($fileName) || !preg_match('/^[a-zA-Z0-9_\-\.]+\.pdf$/i', $fileName)) {
    http_response_code(400);
    echo "Invalid document request.";
    exit;
}

$filePath = __DIR__ . '/shared_docs/' . $fileName;

if (!file_exists($filePath)) {
    http_response_code(404);
    echo "Document not found or may have expired.";
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
