<?php
/**
 * Command-line update entry, for cron jobs that can run PHP directly.
 *
 *   /usr/local/bin/php /home/USER/public_html/cms/update-cli.php
 *
 * No key is required: the ability to run this file already implies filesystem
 * access. Set PAGECORE_CONFIG, and PAGECORE_DOCUMENT_ROOT where the
 * configuration derives paths from the server's document root.
 *
 * Exit codes: 0 applied or already current, 1 failed, 2 misconfigured.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', array('check', 'quiet'));
$checkOnly = array_key_exists('check', $options);
$quiet = array_key_exists('quiet', $options);

try {
    require __DIR__ . '/engine.php';
    require __DIR__ . '/update-service.php';
} catch (Throwable $error) {
    fwrite(STDERR, 'Pagecore could not start: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}

function cms_update_cli_write($line, $quiet) {
    if (!$quiet) { fwrite(STDOUT, $line . PHP_EOL); }
}

if (!cms_update_enabled()) {
    cms_update_cli_write('Updates are switched off for this instance.', $quiet);
    exit(0);
}

if ($checkOnly) {
    $state = cms_update_check(true);
    $latest = is_array($state['latest']) ? PagecoreUpdatePolicy::describe($state['latest']) : 'unknown';
    cms_update_cli_write($state['decision'] . ': ' . $state['reason'], $quiet);
    cms_update_cli_write('installed ' . PagecoreUpdatePolicy::describe(cms_build_identity()) . ', published ' . $latest, $quiet);
    exit($state['decision'] === 'error' || $state['decision'] === 'malformed' ? 1 : 0);
}

$result = cms_update_run(true);
cms_update_cli_write($result['status'] . ': ' . $result['message'], $quiet);

switch ($result['status']) {
    case 'applied':
    case 'up_to_date':
    case 'available':
    case 'throttled':
        exit(0);
    case 'disabled':
        exit(2);
    default:
        // busy, blocked, failed
        fwrite(STDERR, 'Pagecore update ' . $result['status'] . ': ' . $result['message'] . PHP_EOL);
        exit(1);
}
