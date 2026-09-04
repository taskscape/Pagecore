<?php
/**
 * Keyed update endpoint for scheduled invocation.
 *
 * No session and no CSRF: the only credential is update_cron_key, which lives
 * in the private configuration. The endpoint fails closed — an installation
 * that never sets a key answers 404, exactly like one with the feature off.
 *
 *   /usr/bin/curl -fsS -m 300 -H "X-Pagecore-Update-Key: KEY" \
 *     "https://example.com/cms/update-cron.php"
 *
 * Prefer cms/update-cli.php where the panel can run PHP directly: it needs no
 * key at all, so nothing sensitive travels over the wire or into access logs.
 */
require __DIR__ . '/engine.php';
require __DIR__ . '/update-service.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json; charset=utf-8');

/** Answer exactly as a site without the feature would, and say nothing more. */
function cms_update_cron_absent() {
    http_response_code(404);
    exit;
}

function cms_update_cron_reply(array $payload, $code = 200) {
    http_response_code($code);
    try { echo PagecoreJsonPolicy::encodeStrict($payload); }
    catch (Throwable $error) { echo '{"status":"failed"}'; }
    exit;
}

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    cms_update_cron_reply(array('status' => 'failed', 'message' => 'Method not allowed.'), 405);
}

$configured = (string) cms_cfg('update_cron_key', '');
if (!PagecoreUpdatePolicy::cronKeyConfigured($configured)) {
    if ($configured !== '') { error_log('Pagecore: update_cron_key is unusable; the cron endpoint stays disabled.'); }
    cms_update_cron_absent();
}

// Header first: a key in the query string is recorded by the server access log.
$supplied = '';
if (isset($_SERVER['HTTP_X_PAGECORE_UPDATE_KEY'])) { $supplied = (string) $_SERVER['HTTP_X_PAGECORE_UPDATE_KEY']; }
if ($supplied === '' && isset($_GET['key']) && is_scalar($_GET['key'])) { $supplied = (string) $_GET['key']; }

if (!PagecoreUpdatePolicy::cronKeyAccepted($configured, $supplied)) {
    cms_audit_event('update.cron', 'failure', array('reason' => 'invalid_key'));
    cms_update_cron_absent();
}

// A client timeout must not kill the process between the two renames.
ignore_user_abort(true);
@set_time_limit(0);

$started = microtime(true);
$dryRun = isset($_GET['dry_run']) && (string) $_GET['dry_run'] === '1';
$result = $dryRun
    ? cms_update_check(true)
    : cms_update_run(true);

if ($dryRun) {
    $latest = is_array($result['latest']) ? $result['latest'] : null;
    cms_update_cron_reply(array(
        'status' => $result['decision'],
        'message' => (string) $result['reason'],
        'from' => PagecoreUpdatePolicy::describe(cms_build_identity()),
        'to' => $latest === null ? null : PagecoreUpdatePolicy::describe($latest),
        'dry_run' => true,
    ));
}

cms_audit_event('update.cron', $result['status'] === 'failed' ? 'failure' : 'success', array('status' => $result['status']));
cms_update_cron_reply(array(
    'status' => $result['status'],
    'message' => $result['message'],
    'from' => isset($result['from']) ? $result['from'] : null,
    'to' => isset($result['to']) ? $result['to'] : null,
    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
));
