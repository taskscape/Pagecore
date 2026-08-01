<?php
require dirname(__DIR__) . '/cms/engine.php';

$failures = array();
function check_concurrency($condition, $message) {
    global $failures;
    if (!$condition) { $failures[] = $message; }
}

$dir = sys_get_temp_dir() . '/pagecore-concurrency-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
$path = $dir . '/content.md';
file_put_contents($path, "old-complete\n");
$GLOBALS['PAGECORE_FORCE_RECOVERABLE_REPLACE'] = true;
$GLOBALS['PAGECORE_ATOMIC_WRITE_FAILURE'] = 'after-recovery';
check_concurrency(cms_atomic_write($path, "new-complete\n") === false, 'Injected replacement failure must be reported.');
check_concurrency(file_get_contents($path) === "old-complete\n", 'Injected failure must preserve the complete live file.');
$recoveries = glob($path . '.replace-*.bak');
check_concurrency(count($recoveries) === 1, 'Injected failure must retain one recoverable copy.');
check_concurrency(!$recoveries || file_get_contents($recoveries[0]) === "old-complete\n", 'Recovery copy must be complete.');
unset($GLOBALS['PAGECORE_FORCE_RECOVERABLE_REPLACE'], $GLOBALS['PAGECORE_ATOMIC_WRITE_FAILURE']);

foreach ((array) glob($dir . '/*') as $file) { @unlink($file); }
@rmdir($dir);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "Concurrency filesystem checks passed.\n";
