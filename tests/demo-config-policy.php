<?php
$mode = isset($argv[1]) ? $argv[1] : '';
$config = dirname(__DIR__) . '/sample-site/config.php';
putenv('PAGECORE_CONFIG=' . $config);
if ($mode === 'development') { putenv('PAGECORE_DEVELOPMENT=1'); }
else { putenv('PAGECORE_DEVELOPMENT'); }

try {
    require dirname(__DIR__) . '/cms/engine.php';
    if ($mode !== 'development') {
        fwrite(STDERR, "Production mode accepted the public demo configuration.\n");
        exit(1);
    }
} catch (RuntimeException $error) {
    if ($mode === 'development') { throw $error; }
    if (strpos($error->getMessage(), 'development-only') === false) { throw $error; }
}

echo "Demo configuration $mode policy passed.\n";
