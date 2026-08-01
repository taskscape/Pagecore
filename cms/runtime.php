<?php
/** Runtime support policy, isolated so it can be tested without booting the CMS. */

define('PAGECORE_MIN_PHP_ID', 80300);
define('PAGECORE_MIN_PHP_VERSION', '8.3.0');

function cms_php_runtime_supported($versionId) {
    return (int) $versionId >= PAGECORE_MIN_PHP_ID;
}

if (!cms_php_runtime_supported(PHP_VERSION_ID)) {
    throw new RuntimeException(
        'Pagecore requires PHP ' . PAGECORE_MIN_PHP_VERSION . ' or newer; running ' . PHP_VERSION . '.'
    );
}
