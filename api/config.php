<?php
/**
 * Production Security & Hostinger Database Configuration
 * Enterprise Web Security, Security Headers, Anti-SQLi & XSS Prevention
 */

// Enterprise Security Headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// CORS Origin Policy
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
} else {
    header("Access-Control-Allow-Origin: *");
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database Connection with Native MySQL Prepared Statements (Anti-SQLi)
function getDatabaseConnection() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host = getenv('DB_HOST') ?: 'localhost';
    $dbname = getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';

    if (empty($dbname) && file_exists(__DIR__ . '/.ht_config.php')) {
        $cfg = include(__DIR__ . '/.ht_config.php');
        if (is_array($cfg)) {
            $host = $cfg['DB_HOST'] ?? $host;
            $dbname = $cfg['DB_NAME'] ?? $dbname;
            $user = $cfg['DB_USER'] ?? $user;
            $pass = $cfg['DB_PASS'] ?? $pass;
        }
    }

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // Forces native MySQL prepared statements
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database connection failed',
            'user_message' => 'डेटाबेस कनेक्शन सुरक्षा त्रुटि।'
        ]);
        exit;
    }
}

// Recursive Input Sanitization & Anti-XSS Helper
function sanitizeInput($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitizeInput($value);
        }
        return $data;
    }
    if (is_string($data)) {
        // Strip null bytes and dangerous script tags
        $data = str_replace(chr(0), '', $data);
        return trim(htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    return $data;
}

function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function getJsonInput() {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true) ?: [];
    return sanitizeInput($decoded);
}

function generateUuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
