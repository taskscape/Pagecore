<?php
require __DIR__ . '/engine.php';
require __DIR__ . '/auth.php';

header('Cache-Control: no-store');
if (!cms_cfg('statistics_enabled')) {
    http_response_code(404);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
if (cms_is_logged_in() || PagecoreStatistics::isBot($_SERVER['HTTP_USER_AGENT'] ?? '')) {
    http_response_code(204);
    exit;
}
if (empty($_SERVER['HTTP_ORIGIN']) || !cms_login_request_same_origin()
    || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') {
    http_response_code(403);
    exit;
}
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0])) !== 'text/plain') {
    http_response_code(415);
    exit;
}
cms_require_no_maintenance(true);

$ticket = file_get_contents('php://input', false, null, 0, 4097);
if ($ticket === false || strlen($ticket) > 4096) {
    http_response_code(400);
    exit;
}
$now = PagecoreTimePolicy::now(cms_cfg('timezone'));
$target = cms_statistics_read_ticket($ticket, $_SESSION['cms_statistics_secret'] ?? '', $now->getTimestamp());
session_write_close();
$relative = $target === null ? null : PagecoreStatistics::sidecar($target['kind'], $target['key']);
if ($relative === null) {
    http_response_code(400);
    exit;
}

$cookieName = cms_statistics_cookie_name();
$cookie = $_COOKIE[$cookieName] ?? '';
// Use the server's client address for short-lived rate buckets only.
$secret = cms_cfg('password_hash');
$minute = intdiv($now->getTimestamp(), 60);
$source = hash_hmac('sha256', 'rate:' . $minute . ':' . cms_login_source(), $secret);
try {
    if (!cms_statistics_check_rate($source, $minute)) {
        http_response_code(429);
        exit;
    }
    if ($target['kind'] === 'post' && !cms_statistics_public_post($target['key'])) {
        http_response_code(400);
        exit;
    }
    $day = $now->format('Y-m-d');
    [$reader, $identifier, $firstDay] = PagecoreStatistics::identity(is_string($cookie) ? $cookie : '', $day, $secret);
    cms_statistics_record($relative, array($day, $identifier, $firstDay, $target['title']), $now);
} catch (Throwable $error) {
    error_log('Pagecore statistics collection failed: ' . $error->getMessage());
    http_response_code(503);
    exit;
}
setcookie($cookieName, $reader, array(
    'expires' => $now->getTimestamp() + PagecoreStatistics::COOKIE_LIFETIME,
    'path' => cms_admin_url('statistics-read.php'),
    'secure' => cms_cfg('cookie_secure'),
    'httponly' => true,
    'samesite' => 'Lax',
));
http_response_code(204);
