<?php

declare(strict_types=1);

$tokenUrl = 'https://node.example.com/challenge/v1/SITE_ID';
$context  = stream_context_create(['http' => ['timeout' => 3]]);
$response = file_get_contents($tokenUrl, false, $context);
$payload  = is_string($response) ? json_decode($response, true) : null;

if (!is_array($payload) || !is_string($payload['token'] ?? null)) {
    http_response_code(503);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo $payload['token'];
