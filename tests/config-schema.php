<?php
require dirname(__DIR__) . '/cms/config-schema.php';

$samplePath = dirname(__DIR__) . '/sample-site/config.php';
$sample = require $samplePath;
$failures = array();
function check_schema($condition, $message) {
    global $failures;
    if (!$condition) { $failures[] = $message; }
}

list($development, $developmentErrors) = cms_validate_config($sample, false);
check_schema($developmentErrors === array(), 'The documented development profile must validate.');

$production = $sample;
$production['development_only'] = false;
$production['demo_credentials'] = false;
$production['require_https'] = true;
$production['cookie_secure'] = true;
$production['hsts'] = true;
$production['site_url'] = 'https://example.test';
$production['content_dir'] = '/srv/pagecore/content';
$production['backup_dir'] = '/srv/pagecore/backups';
$production['uploads_dir'] = '/srv/pagecore/uploads';
$production['login_rate_limit_dir'] = '/srv/pagecore/state/login';
$production['audit_log_path'] = '/srv/pagecore/state/audit.jsonl';
list(, $productionErrors) = cms_validate_config($production, true);
check_schema($productionErrors === array(), 'A secure production profile must validate: ' . implode('; ', $productionErrors));

$cases = array(
    'relative content path' => array('content_dir', 'content', false, 'absolute path'),
    'duplicate storage path' => array('backup_dir', $sample['content_dir'], false, 'must not equal'),
    'invalid password hash' => array('password_hash', 'plaintext', false, 'supported password hash'),
    'missing slug placeholder' => array('post_url', '/post/', false, '{slug}'),
    'unsafe upload extension' => array('allowed_ext', array('jpg', 'php'), false, 'unsafe'),
    'invalid limit' => array('max_content_bytes', 0, false, 'positive integer'),
    'insecure production transport' => array('require_https', false, true, 'production requires require_https'),
);
foreach ($cases as $name => $case) {
    $config = $case[2] ? $production : $sample;
    $config[$case[0]] = $case[1];
    list(, $errors) = cms_validate_config($config, $case[2]);
    check_schema(strpos(implode('; ', $errors), $case[3]) !== false, $name . ' was not rejected.');
}

$engine = file_get_contents(dirname(__DIR__) . '/cms/engine.php');
$schemaLoad = strpos($engine, "require_once __DIR__ . '/config-schema.php'");
check_schema($schemaLoad < strpos($engine, 'PagecoreSessionContext::start'), 'Schema validation must run before session startup.');
check_schema(strpos($engine, "require_once __DIR__ . '/config-schema.php'") < strpos($engine, "require_once __DIR__ . '/audit.php'"), 'Schema validation must run before runtime logging or writes.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Configuration schema checks passed.\n";
