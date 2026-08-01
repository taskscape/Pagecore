<?php

define('CMS_CONFIG_FILE', dirname(__DIR__) . '/sample-site/config.php');
require dirname(__DIR__) . '/cms/auth.php';

$invalid = array(
    '',
    '//evil.example/path',
    '/\\evil.example/path',
    '/%5cevil.example/path',
    '/%255cevil.example/path',
    '/%2fevil.example/path',
    '/%252fevil.example/path',
    'https://evil.example/path',
    "https:\\evil.example/path",
    "/safe\r\nLocation: https://evil.example/",
    '/safe%0d%0aLocation:%20https://evil.example/',
);
foreach ($invalid as $value) {
    if (cms_safe_redirect_target($value) !== '/') {
        fwrite(STDERR, "FAIL: unsafe redirect was accepted: " . json_encode($value) . "\n");
        exit(1);
    }
}

$valid = array(
    '/',
    '/sample-site/',
    '/cms/content.php?page=2&q=launch%20notes#posts',
    '/post/example/?preview=1',
);
foreach ($valid as $value) {
    if (cms_safe_redirect_target($value) !== $value) {
        fwrite(STDERR, "FAIL: local redirect changed: $value\n");
        exit(1);
    }
}

fwrite(STDOUT, "PASS: login redirects accept only unambiguous root-relative destinations\n");
