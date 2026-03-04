<?php
$route = $_GET['route'] ?? '';

match($route) {
    'download' => require __DIR__ . '/../../../private/yt-dlp/api/download.php',
    'serve' => require __DIR__ . '/../../../private/yt-dlp/api/serve.php',
    default => http_response_code(404),
};