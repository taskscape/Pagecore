<?php

require __DIR__ . '/engine.php';

$rel = isset($_GET['path']) ? (string) $_GET['path'] : '';
$ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
$types = array(
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
);
if (!isset($types[$ext])) {
    http_response_code(404);
    exit;
}

$path = cms_media_path($rel, true);
if (!$path) {
    http_response_code(404);
    exit;
}

$filename = basename($path);
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
if ($ext === 'pdf') {
    header('Content-Disposition: attachment; filename="download.pdf"; filename*=UTF-8\'\'' . rawurlencode($filename));
} else {
    header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($filename));
}
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'none'");
header('Cache-Control: public, max-age=3600');
readfile($path);
