<?php
/**
 * Authentication: login/logout, CSRF issuance, brute-force lockout.
 * Requires engine.php to be loaded first.
 */

if (!defined('CMS_LOADED')) { require __DIR__ . '/engine.php'; }

function cms_lock_file() {
    return sys_get_temp_dir() . '/cms_lock_' . md5(__DIR__);
}

/** array(failures, first_failure_ts) */
function cms_lock_state() {
    $f = cms_lock_file();
    if (!is_file($f)) { return array(0, 0); }
    $parts = explode(':', (string) @file_get_contents($f));
    return array((int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0));
}

function cms_is_locked_out() {
    list($fails, $since) = cms_lock_state();
    if ($fails < 5) { return false; }
    if (time() - $since > 300) { @unlink(cms_lock_file()); return false; }
    return true;
}

function cms_record_failure() {
    list($fails, $since) = cms_lock_state();
    if ($fails === 0 || time() - $since > 300) { $fails = 0; $since = time(); }
    @file_put_contents(cms_lock_file(), ($fails + 1) . ':' . $since);
}

function cms_login($user, $pass) {
    if (cms_is_locked_out()) { return false; }
    $ok = hash_equals(cms_cfg('username'), (string) $user)
        && password_verify((string) $pass, cms_cfg('password_hash'));
    if (!$ok) {
        cms_record_failure();
        sleep(1); // slow down guessing
        return false;
    }
    @unlink(cms_lock_file());
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
        echo json_encode(array('ok' => false, 'error' => 'Wymagane logowanie.'));
        exit;
    }
    $sent = isset($_SERVER['HTTP_X_CMS_TOKEN']) ? $_SERVER['HTTP_X_CMS_TOKEN'] : '';
    if ($_SERVER['REQUEST_METHOD'] !== 'GET'
        && (!$sent || !hash_equals(cms_csrf_token(), $sent))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'error' => 'Nieprawidłowy token bezpieczeństwa.'));
        exit;
    }
}
