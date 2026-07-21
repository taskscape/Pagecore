<?php
/**
 * Authentication: login/logout, CSRF issuance, session-scoped login throttling.
 * Requires engine.php to be loaded first.
 */

if (!defined('CMS_LOADED')) { require __DIR__ . '/engine.php'; }

/**
 * Return failures for this anonymous browser session only. A shared server-side
 * lock would let one unauthenticated client deny the editor access to the CMS.
 */
function cms_lock_state() {
    return array(
        isset($_SESSION['cms_login_failures']) ? (int) $_SESSION['cms_login_failures'] : 0,
        isset($_SESSION['cms_login_first_failure_at']) ? (int) $_SESSION['cms_login_first_failure_at'] : 0,
    );
}

/** Clear the current browser's temporary throttle state after success or expiry. */
function cms_clear_lock_state() {
    unset($_SESSION['cms_login_failures'], $_SESSION['cms_login_first_failure_at']);
}

function cms_is_locked_out() {
    list($fails, $since) = cms_lock_state();
    if ($fails < 5) { return false; }
    if (time() - $since > 300) { cms_clear_lock_state(); return false; }
    return true;
}

function cms_record_failure() {
    list($fails, $since) = cms_lock_state();
    if ($fails === 0 || time() - $since > 300) { $fails = 0; $since = time(); }
    $_SESSION['cms_login_failures'] = $fails + 1;
    $_SESSION['cms_login_first_failure_at'] = $since;
    return $fails + 1;
}

function cms_login($user, $pass) {
    if (cms_is_locked_out()) { return false; }
    $ok = hash_equals(cms_cfg('username'), (string) $user)
        && password_verify((string) $pass, cms_cfg('password_hash'));
    if (!$ok) {
        $failures = cms_record_failure();
        // Increase delay only for this session; it slows guessing without creating a global DoS switch.
        sleep(min(4, max(1, $failures - 1)));
        return false;
    }
    cms_clear_lock_state();
    session_regenerate_id(true);
    $_SESSION['cms_auth']    = true;
    $_SESSION['cms_auth_at'] = time();
    $_SESSION['cms_csrf']    = bin2hex(random_bytes(32));
    return true;
}

function cms_logout() {
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'],
            isset($p['domain']) ? $p['domain'] : '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** API guard: valid session + CSRF header, else JSON error + exit. */
function cms_require_auth() {
    if (!cms_is_logged_in()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'error' => 'Authentication is required.'));
        exit;
    }
    $sent = isset($_SERVER['HTTP_X_CMS_TOKEN']) ? $_SERVER['HTTP_X_CMS_TOKEN'] : '';
    if ($_SERVER['REQUEST_METHOD'] !== 'GET'
        && (!$sent || !hash_equals(cms_csrf_token(), $sent))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'error' => 'Invalid security token.'));
        exit;
    }
}
