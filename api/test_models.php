<?php
require_once __DIR__ . '/config.php';
$cfg = include(__DIR__ . '/.ht_config.php');
$apiKey = $cfg['GEMINI_API_KEY'] ?? '';

$models = ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash', 'gemini-1.5-flash-latest'];
$results = [];

foreach ($models as $m) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $m . ":generateContent?key=" . urlencode($apiKey);
    $payload = [
        'contents' => [
            ['parts' => [['text' => 'Ping test. Reply with OK.']]]
        ]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $results[$m] = ['code' => $code, 'body' => json_decode($res, true)];
}

header('Content-Type: application/json');
echo json_encode($results);
