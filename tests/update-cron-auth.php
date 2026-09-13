<?php

require dirname(__DIR__) . '/cms/modules/update-policy.php';
require dirname(__DIR__) . '/cms/modules/update-state.php';
require dirname(__DIR__) . '/cms/config-schema.php';

$failures = array();
function cron_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$key = bin2hex(random_bytes(16));
$state = sys_get_temp_dir() . '/pagecore-cron-' . bin2hex(random_bytes(6));

/* ------------------------------------------------------- endpoint gating */
// An empty key is the shipped default: the endpoint must simply not exist.
cron_check(!PagecoreUpdatePolicy::cronKeyConfigured(''), 'An unset key enabled the cron endpoint.');
cron_check(!PagecoreUpdatePolicy::cronKeyConfigured('short'), 'A short key enabled the cron endpoint.');
cron_check(!PagecoreUpdatePolicy::cronKeyConfigured(str_repeat('x', 31)), 'A 31-character key enabled the cron endpoint.');
cron_check(PagecoreUpdatePolicy::cronKeyConfigured(str_repeat('x', 32)), 'A 32-character key did not enable the endpoint.');
cron_check(!PagecoreUpdatePolicy::cronKeyConfigured('REPLACE_WITH_A_REAL_UPDATE_CRON_KEY_VALUE'), 'The documented placeholder enabled the endpoint.');
cron_check(!PagecoreUpdatePolicy::cronKeyConfigured(null), 'A non-string key enabled the endpoint.');

/* ---------------------------------------------------------- key matching */
cron_check(PagecoreUpdatePolicy::cronKeyAccepted($key, $key), 'The configured key was rejected.');
cron_check(!PagecoreUpdatePolicy::cronKeyAccepted($key, strtoupper($key)), 'Key comparison was case insensitive.');
cron_check(!PagecoreUpdatePolicy::cronKeyAccepted($key, substr($key, 0, -1)), 'A prefix of the key was accepted.');
cron_check(!PagecoreUpdatePolicy::cronKeyAccepted($key, $key . '0'), 'A superstring of the key was accepted.');
cron_check(!PagecoreUpdatePolicy::cronKeyAccepted($key, ''), 'An absent request key was accepted.');
cron_check(!PagecoreUpdatePolicy::cronKeyAccepted('', $key), 'A disabled endpoint accepted a request key.');
cron_check(!PagecoreUpdatePolicy::cronKeyAccepted('short', 'short'), 'An unusable configured key still authorised a request.');

/* ------------------------------------------------ configuration validation */
$base = array(
    'update_channel' => 'main', 'update_apply' => false, 'update_auto_apply' => true,
    'update_check_on_admin' => true, 'update_allow_downgrade' => false,
    'update_manifest_url' => 'https://raw.githubusercontent.com/taskscape/Pagecore/main/release/latest.json',
    'update_allowed_hosts' => array('raw.githubusercontent.com'),
    'update_preserve' => array('config.php'), 'update_cron_key' => '',
    'update_check_ttl_seconds' => 21600, 'update_cron_min_interval_seconds' => 300,
    'update_keep' => 3, 'update_http_timeout_seconds' => 20,
    'update_download_timeout_seconds' => 120, 'update_max_archive_bytes' => 26214400,
    'update_state_dir' => '/srv/pagecore/state', 'update_work_dir' => '/srv/pagecore/updates',
    'update_ca_bundle' => '',
);
cron_check(cms_update_config_errors($base) === array(), 'A valid update configuration reported errors.');
cron_check(cms_update_config_errors(array_merge($base, array('update_ca_bundle' => '/etc/ssl/certs/ca-certificates.crt'))) === array(), 'An absolute CA bundle path failed validation.');
cron_check(cms_update_config_errors(array_merge($base, array('update_ca_bundle' => 'certs/ca.crt'))) !== array(), 'A relative CA bundle path passed validation.');
cron_check(cms_update_config_errors(array_merge($base, array('update_cron_key' => 'tooshort'))) !== array(), 'A short cron key passed validation.');
cron_check(cms_update_config_errors(array_merge($base, array('update_cron_key' => $key . $key))) === array(), 'A strong cron key failed validation.');
cron_check(cms_update_config_errors(array_merge($base, array('update_channel' => 'nightly'))) !== array(), 'An unknown channel passed validation.');
cron_check(cms_update_config_errors(array_merge($base, array('update_manifest_url' => 'http://raw.githubusercontent.com/x.json'))) !== array(), 'A plain HTTP feed URL passed validation.');
cron_check(cms_update_config_errors(array_merge($base, array('update_manifest_url' => 'https://evil.test/x.json'))) !== array(), 'A feed URL off the allowlist passed validation.');
cron_check(cms_update_config_errors(array_merge($base, array('update_preserve' => array('../outside.php')))) !== array(), 'A traversal path in update_preserve passed validation.');
cron_check(cms_update_config_errors(array_merge($base, array('update_apply' => 1))) !== array(), 'A non-boolean update_apply passed validation.');
cron_check(cms_update_config_errors(array_merge($base, array('update_allowed_hosts' => array()))) !== array(), 'An empty host allowlist passed validation.');

$defaults = cms_update_defaults(array('content_dir' => '/srv/pagecore/content'));
cron_check($defaults['update_apply'] === false, 'Applying updates was not disabled by default.');
cron_check($defaults['update_cron_key'] === '', 'A cron key was enabled by default.');
cron_check($defaults['update_channel'] === 'main', 'The default channel changed.');
cron_check($defaults['update_state_dir'] === '/srv/pagecore/content/.state', 'The state directory default did not follow the content directory.');
cron_check($defaults['update_work_dir'] === '/srv/pagecore/content/.state/updates', 'The work directory default did not follow the state directory.');

/* ----------------------------------------------- throttling before egress */
$now = 1788502931;
$fresh = array_merge(PagecoreUpdateState::blank(), array('last_attempt_at' => $now - 10));
cron_check(PagecoreUpdateState::throttled($fresh, 300, $now), 'A request inside the interval was not throttled.');
cron_check(!PagecoreUpdateState::throttled($fresh, 300, $now + 300), 'A request past the interval stayed throttled.');
cron_check(!PagecoreUpdateState::throttled(PagecoreUpdateState::blank(), 300, $now), 'A first request was throttled.');
// A clock that jumped backwards must not lock the endpoint out indefinitely.
$future = array_merge(PagecoreUpdateState::blank(), array('last_attempt_at' => $now + 86400));
cron_check(!PagecoreUpdateState::throttled($future, 300, $now), 'A future timestamp permanently throttled the endpoint.');

cron_check(PagecoreUpdateState::isStale(PagecoreUpdateState::blank(), 21600, $now), 'A never-checked instance was treated as fresh.');
$checked = array_merge(PagecoreUpdateState::blank(), array('checked_at' => $now - 60));
cron_check(!PagecoreUpdateState::isStale($checked, 21600, $now), 'A recent check was treated as stale.');
cron_check(PagecoreUpdateState::isStale($checked, 21600, $now + 21600), 'An expired check was treated as fresh.');

/* --------------------------------------------------------- state lifecycle */
cron_check(PagecoreUpdateState::write($state, array_merge(PagecoreUpdateState::blank(), array('decision' => 'available'))), 'State could not be written.');
cron_check(PagecoreUpdateState::read($state)['decision'] === 'available', 'State did not round-trip.');
file_put_contents(PagecoreUpdateState::statePath($state), 'not json');
cron_check(PagecoreUpdateState::read($state)['decision'] === 'unknown', 'Corrupt state was not replaced by a blank record.');

$lock = PagecoreUpdateState::lock($state);
cron_check($lock !== null, 'The lock could not be acquired.');
PagecoreUpdateState::unlock($lock);

cron_check(!PagecoreUpdateState::maintenanceActive($state), 'Maintenance was active before it was set.');
PagecoreUpdateState::setMaintenance($state, 'test');
cron_check(PagecoreUpdateState::maintenanceActive($state), 'Maintenance was not registered.');
PagecoreUpdateState::clearMaintenance($state);
cron_check(!PagecoreUpdateState::maintenanceActive($state), 'Maintenance was not cleared.');
// A crashed update must not hold the admin panel closed forever.
PagecoreUpdateState::setMaintenance($state, 'stale', time() - (PagecoreUpdateState::MAINTENANCE_SECONDS + 10));
cron_check(!PagecoreUpdateState::maintenanceActive($state), 'An expired maintenance window stayed active.');

foreach (array('update-status.json', 'update.lock', 'maintenance.json') as $file) { @unlink($state . '/' . $file); }
@rmdir($state);

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
fwrite(STDOUT, "PASS: cron endpoint fails closed without a strong key and throttles before any network egress\n");
