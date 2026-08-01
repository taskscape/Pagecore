<?php

define('CMS_CONFIG_FILE', dirname(__DIR__) . '/sample-site/config.php');
require dirname(__DIR__) . '/cms/auth.php';

$stateDir = sys_get_temp_dir() . '/pagecore-login-throttle-' . bin2hex(random_bytes(6));
$GLOBALS['CMS_CONFIG']['login_rate_limit_dir'] = $stateDir;
$GLOBALS['CMS_CONFIG']['login_rate_window_seconds'] = 60;
$GLOBALS['CMS_CONFIG']['login_rate_source_limit'] = 3;
$GLOBALS['CMS_CONFIG']['login_rate_account_limit'] = 5;
$now = 100000;

function throttle_fail($message) {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

try {
    for ($i = 0; $i < 3; $i++) {
        $state = cms_login_throttle_evaluate('admin', '192.0.2.10', $now + $i, true);
    }
    if (!$state['locked'] || $state['source_failures'] !== 3 || $state['retry_after'] < 58) {
        throttle_fail('single-source budget did not lock predictably');
    }
    if (!cms_login_throttle_evaluate('admin', '192.0.2.10', $now + 3)['locked']) {
        throttle_fail('fresh session/source lookup reset the shared budget');
    }

    @unlink(cms_login_throttle_file());
    foreach (array('192.0.2.11', '192.0.2.11', '192.0.2.12', '192.0.2.12', '192.0.2.13') as $offset => $source) {
        $state = cms_login_throttle_evaluate('admin', $source, $now + $offset, true);
    }
    $distributed = cms_login_throttle_evaluate('admin', '192.0.2.99', $now + 5);
    if (!$distributed['locked'] || $distributed['source_failures'] !== 0 || $distributed['account_failures'] !== 5) {
        throttle_fail('distributed account budget was not enforced independently of source');
    }

    $recovered = cms_login_throttle_evaluate('admin', '192.0.2.99', $now + 65);
    if ($recovered['locked'] || $recovered['source_failures'] !== 0 || $recovered['account_failures'] !== 0) {
        throttle_fail('expired attempts did not recover automatically');
    }

    cms_login_throttle_evaluate('admin', '192.0.2.20', $now + 70, true);
    if (!cms_clear_login_throttle('admin', '192.0.2.20')) { throttle_fail('successful-login cleanup failed'); }
    $cleared = cms_login_throttle_evaluate('admin', '192.0.2.20', $now + 71);
    if ($cleared['source_failures'] !== 0 || $cleared['account_failures'] !== 0) {
        throttle_fail('successful-login cleanup retained failures');
    }
} finally {
    @unlink($stateDir . '/login-rate-limits.json');
    @rmdir($stateDir);
}

fwrite(STDOUT, "PASS: shared source/account throttles survive sessions, expire, and recover\n");
