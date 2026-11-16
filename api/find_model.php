<?php
require_once __DIR__ . '/config.php';
$cfg = include(__DIR__ . '/.ht_config.php');
$apiKey = $cfg['GEMINI_API_KEY'] ?? '';

$listUrl = "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($apiKey);
$ch = curl_init($listUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$listRes = curl_exec($ch);
curl_close($ch);

$listData = json_decode($listRes, true);
$models = $listData['models'] ?? [];

$workingModel = null;
$tested = [];

foreach ($models as $m) {
    $methods = $m['supportedGenerationMethods'] ?? [];
    if (in_array('generateContent', $methods)) {
        $mName = str_replace('models/', '', $m['name']);
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $mName . ":generateContent?key=" . urlencode($apiKey);
        $payload = ['contents' => [['parts' => [['text' => 'Ping test']]]]];
        
        $ch2 = curl_init($url);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
        $res = curl_exec($ch2);
        $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        $tested[$mName] = $code;
        if ($code === 200) {
            $workingModel = $mName;
            break;
        }
    }
}

header('Content-Type: application/json');
echo json_encode(['working_model' => $workingModel, 'tested' => $tested]);
