<?php
require dirname(__DIR__) . '/cms/runtime.php';

$cases = array(70400 => false, 80299 => false, 80300 => true, 80400 => true, 80500 => true);
foreach ($cases as $version => $expected) {
    if (cms_php_runtime_supported($version) !== $expected) {
        fwrite(STDERR, "Unexpected support result for $version\n");
        exit(1);
    }
}

echo PHP_VERSION . ' satisfies Pagecore >= ' . PAGECORE_MIN_PHP_VERSION . "\n";
