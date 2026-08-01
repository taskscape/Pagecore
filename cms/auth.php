<?php

/** Accept only unambiguous root-relative redirects and preserve the original URL. */
function cms_safe_redirect_target($candidate, $fallback = '/') {
    $candidate = (string) $candidate;
    if ($candidate === '') { return $fallback; }

    $inspected = $candidate;
    for ($i = 0; $i < 3; $i++) {
        if (strpos($inspected, '\\') !== false || preg_match('/[\x00-\x1f\x7f]/', $inspected)) {
            return $fallback;
        }
        $parts = parse_url($inspected);
        if ($parts === false
            || isset($parts['scheme']) || isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || !isset($parts['path']) || $parts['path'] === ''
            || $parts['path'][0] !== '/' || strpos($parts['path'], '//') === 0) {
            return $fallback;
        }
        $decoded = rawurldecode($inspected);
        if ($decoded === $inspected) { break; }
        $inspected = $decoded;
    }

    if (preg_match('/%(?:00|0a|0d|2f|5c)/i', $inspected)) { return $fallback; }
    return $candidate;
}
/** Authentication, shared login throttling, logout, and CSRF issuance. */

if (!defined('CMS_LOADED')) { require __DIR__ . '/engine.php'; }

function cms_login_source() {
    return isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] !== ''
        ? (string) $_SERVER['REMOTE_ADDR']
        : 'unknown';
}

function cms_login_throttle_file() {
    $dir = cms_cfg('login_rate_limit_dir', cms_cfg('content_dir') . '/.state');
    return rtrim($dir, '/\\') . '/login-rate-limits.json';
}

function cms_login_throttle_key($kind, $value) {
    return hash('sha256', $kind . "\0" . strtolower(trim((string) $value)));
}

/** Serialize rate-limit reads and writes across sessions and PHP workers. */
function cms_login_throttle_transaction($callback) {
    $path = cms_login_throttle_file();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { return false; }
    $handle = @fopen($path, 'c+b');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) { fclose($handle); }
        return false;
    }
    rewind($handle);
    $decoded = json_decode((string) stream_get_contents($handle), true);
    $state = is_array($decoded) ? $decoded : array();
    if (!isset($state['sources']) || !is_array($state['sources'])) { $state['sources'] = array(); }
    if (!isset($state['accounts']) || !is_array($state['accounts'])) { $state['accounts'] = array(); }
    $result = call_user_func_array($callback, array(&$state));
    $json = json_encode($state, JSON_UNESCAPED_SLASHES);
    $written = $json !== false && ftruncate($handle, 0) && rewind($handle)
        && fwrite($handle, $json . "\n") !== false && fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $written ? $result : false;
}

function cms_login_throttle_evaluate($user, $source = null, $now = null, $recordFailure = false) {
    $source = $source === null ? cms_login_source() : (string) $source;
    $now = $now === null ? time() : (int) $now;
    $window = max(1, (int) cms_cfg('login_rate_window_seconds', 300));
    $sourceLimit = max(1, (int) cms_cfg('login_rate_source_limit', 5));
    $accountLimit = max($sourceLimit, (int) cms_cfg('login_rate_account_limit', 10));
    $sourceKey = cms_login_throttle_key('source', $source);
    $accountKey = cms_login_throttle_key('account', $user);

    $result = cms_login_throttle_transaction(function (&$state) use (
        $now, $window, $sourceLimit, $accountLimit, $sourceKey, $accountKey, $recordFailure
    ) {
        $cutoff = $now - $window;
        foreach (array('sources', 'accounts') as $group) {
            foreach ($state[$group] as $key => $timestamps) {
                $timestamps = array_values(array_filter((array) $timestamps, function ($value) use ($cutoff) {
                    return is_numeric($value) && (int) $value > $cutoff;
                }));
                if ($timestamps) { $state[$group][$key] = $timestamps; }
                else { unset($state[$group][$key]); }
            }
        }
        if ($recordFailure) {
            $state['sources'][$sourceKey][] = $now;
            $state['accounts'][$accountKey][] = $now;
        }
        $sourceTimes = isset($state['sources'][$sourceKey]) ? $state['sources'][$sourceKey] : array();
        $accountTimes = isset($state['accounts'][$accountKey]) ? $state['accounts'][$accountKey] : array();
        $locked = count($sourceTimes) >= $sourceLimit || count($accountTimes) >= $accountLimit;
        $releaseAt = $now;
        if (count($sourceTimes) >= $sourceLimit) { $releaseAt = max($releaseAt, (int) $sourceTimes[0] + $window); }
        if (count($accountTimes) >= $accountLimit) { $releaseAt = max($releaseAt, (int) $accountTimes[0] + $window); }
        return array(
            'locked' => $locked,
            'retry_after' => $locked ? max(1, $releaseAt - $now) : 0,
            'source_failures' => count($sourceTimes),
            'account_failures' => count($accountTimes),
        );
    });
    return $result === false
        ? array('locked' => true, 'retry_after' => 60, 'source_failures' => 0, 'account_failures' => 0)
        : $result;
}

function cms_clear_login_throttle($user, $source = null) {
    $sourceKey = cms_login_throttle_key('source', $source === null ? cms_login_source() : $source);
    $accountKey = cms_login_throttle_key('account', $user);
    return cms_login_throttle_transaction(function (&$state) use ($sourceKey, $accountKey) {
        unset($state['sources'][$sourceKey], $state['accounts'][$accountKey]);
        return true;
    });
}

function cms_is_locked_out($user = null) {
    $user = $user === null ? cms_cfg('username') : $user;
    $state = cms_login_throttle_evaluate($user);
    $GLOBALS['CMS_LOGIN_THROTTLE'] = $state;
    return $state['locked'];
}

function cms_login_retry_after() {
    return isset($GLOBALS['CMS_LOGIN_THROTTLE']['retry_after'])
        ? (int) $GLOBALS['CMS_LOGIN_THROTTLE']['retry_after']
        : 0;
}

function cms_login($user, $pass) {
    $state = cms_login_throttle_evaluate($user);
    $GLOBALS['CMS_LOGIN_THROTTLE'] = $state;
    if ($state['locked']) {
        cms_audit_event('auth.login', 'failure', array('account' => $user, 'reason' => 'throttled', 'retry_after' => $state['retry_after']));
        return false;
    }
    $ok = hash_equals(cms_cfg('username'), (string) $user)
        && password_verify((string) $pass, cms_cfg('password_hash'));
    if (!$ok) {
        $GLOBALS['CMS_LOGIN_THROTTLE'] = cms_login_throttle_evaluate($user, null, null, true);
        cms_audit_event('auth.login', 'failure', array('account' => $user, 'reason' => 'credentials'));
        return false;
    }
    cms_clear_login_throttle($user);
    session_regenerate_id(true);
    $_SESSION['cms_auth']    = true;
    $_SESSION['cms_auth_at'] = time();
    $_SESSION['cms_csrf']    = bin2hex(random_bytes(32));
    cms_audit_event('auth.login', 'success', array('account' => $user));
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
        cms_audit_event('auth.api', 'failure', array('reason' => 'unauthenticated', 'status' => 401));
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'error' => 'Authentication is required.'));
        exit;
    }
    $sent = isset($_SERVER['HTTP_X_CMS_TOKEN']) ? $_SERVER['HTTP_X_CMS_TOKEN'] : '';
    if ($_SERVER['REQUEST_METHOD'] !== 'GET'
        && (!$sent || !hash_equals(cms_csrf_token(), $sent))) {
        cms_audit_event('auth.csrf', 'failure', array('reason' => 'invalid_token', 'status' => 403));
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'error' => 'Invalid security token.'));
        exit;
    }
}
