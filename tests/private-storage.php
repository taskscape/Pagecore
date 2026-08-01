<?php

putenv('PAGECORE_DEVELOPMENT=1');

define('CMS_CONFIG_FILE', dirname(__DIR__) . '/sample-site/config.php');
require dirname(__DIR__) . '/cms/engine.php';

$documentRoot = realpath(dirname(__DIR__));
$violations = cms_private_storage_violations($documentRoot, CMS_CONFIG_FILE);
foreach (array('configuration', 'content_dir', 'backup_dir', 'uploads_dir') as $expected) {
    if (!in_array($expected, $violations, true)) {
        fwrite(STDERR, "FAIL: demo in-tree path was not identified: $expected\n");
        exit(1);
    }
}

$privateRoot = dirname($documentRoot) . '/pagecore-private-example';
$GLOBALS['CMS_CONFIG']['content_dir'] = $privateRoot . '/content';
$GLOBALS['CMS_CONFIG']['backup_dir'] = $privateRoot . '/backups';
$GLOBALS['CMS_CONFIG']['uploads_dir'] = $privateRoot . '/uploads';
$GLOBALS['CMS_CONFIG']['login_rate_limit_dir'] = $privateRoot . '/state';
$safe = cms_private_storage_violations($documentRoot, $privateRoot . '/config.php');
if ($safe !== array()) {
    fwrite(STDERR, 'FAIL: external paths were rejected: ' . implode(', ', $safe) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: production storage policy rejects document-root secrets and mutable data\n");
