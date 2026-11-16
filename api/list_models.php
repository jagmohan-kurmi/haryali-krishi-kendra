<?php
require_once __DIR__ . '/config.php';
$cfg = include(__DIR__ . '/.ht_config.php');
$apiKey = $cfg['GEMINI_API_KEY'] ?? '';

$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($apiKey);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: application/json');
echo $res;
