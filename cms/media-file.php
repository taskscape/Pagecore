<?php

require __DIR__ . '/engine.php';

$rel = isset($_GET['path']) ? (string) $_GET['path'] : '';
if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'pdf') {
    http_response_code(404);
    exit;
}

$path = cms_media_path($rel, true);
if (!$path) {
    http_response_code(404);
    exit;
}

$filename = basename($path);
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="download.pdf"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'none'");
header('Cache-Control: public, max-age=3600');
readfile($path);
