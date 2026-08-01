<?php
/** Privacy-safe, append-only JSON-lines security audit log. */

function cms_correlation_id() {
    if (!empty($GLOBALS['CMS_CORRELATION_ID'])) { return $GLOBALS['CMS_CORRELATION_ID']; }
    $candidate = isset($_SERVER['HTTP_X_REQUEST_ID']) ? (string) $_SERVER['HTTP_X_REQUEST_ID'] : '';
    if (!preg_match('/^[A-Za-z0-9._-]{8,64}$/', $candidate)) {
        $candidate = bin2hex(random_bytes(12));
    }
    $GLOBALS['CMS_CORRELATION_ID'] = $candidate;
    return $candidate;
}

function cms_audit_hash($kind, $value) {
    $key = (string) cms_cfg('audit_hash_key', cms_cfg('password_hash', 'pagecore-audit'));
    return hash_hmac('sha256', $kind . "\0" . strtolower(trim((string) $value)), $key);
}

function cms_audit_event($event, $outcome, array $context = array()) {
    if (cms_cfg('audit_enabled', true) !== true) { return false; }
    $event = preg_replace('/[^a-z0-9._-]/', '_', strtolower((string) $event));
    $record = array(
        'timestamp' => gmdate('c'),
        'correlation_id' => cms_correlation_id(),
        'event' => $event,
        'outcome' => $outcome === 'success' ? 'success' : 'failure',
        'account_hash' => cms_audit_hash('account', isset($context['account']) ? $context['account'] : cms_cfg('username', '')),
        'source_hash' => cms_audit_hash('source', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown'),
    );
    foreach ($context as $key => $value) {
        $key = strtolower((string) $key);
        if ($key === 'account' || preg_match('/pass|token|secret|markdown|content|path/', $key)) { continue; }
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) || (!is_scalar($value) && $value !== null)) { continue; }
        $record[$key] = is_string($value) ? substr($value, 0, 128) : $value;
    }
    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) { return false; }

    $path = cms_cfg('audit_log_path', cms_cfg('content_dir') . '/.state/audit.jsonl');
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log('Pagecore audit event could not be persisted.');
        return false;
    }
    $maxBytes = max(1024, (int) cms_cfg('audit_max_bytes', 5242880));
    if (is_file($path) && filesize($path) >= $maxBytes) {
        @rename($path, $path . '.1');
    }
    $handle = @fopen($path, 'ab');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) { fclose($handle); }
        error_log('Pagecore audit event could not be persisted.');
        return false;
    }
    $written = fwrite($handle, $json . "\n") !== false && fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($path, 0600);
    return $written;
}
