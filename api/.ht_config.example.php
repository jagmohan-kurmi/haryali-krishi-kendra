<?php
// Example configuration template
return [
    'DB_HOST' => getenv('DB_HOST') ?: 'localhost',
    'DB_NAME' => getenv('DB_NAME') ?: 'krishi_kendra_db',
    'DB_USER' => getenv('DB_USER') ?: 'root',
    'DB_PASS' => getenv('DB_PASS') ?: '',
    'GEMINI_API_KEY' => getenv('GEMINI_API_KEY') ?: ''
];
