<?php

require dirname(__DIR__) . '/cms/request-guard.php';

$denied = array(
    '/cms/engine.php',
    '/cms/request-guard.php',
    '/cms/lib/Parsedown.php',
    '/cms/%65ngine.php',
    '/cms/%252e%252e/engine.php',
    '/cms/../engine.php',
    '/cms/..%2fengine.php',
    '/cms%2fengine.php',
    '/cms\\engine.php',
    '/content/private.md',
    '/uploads/shell.PHp8',
    '/uploads/nested/shell.phtml',
);

foreach ($denied as $uri) {
    if (!pagecore_request_is_denied($uri, array('/content'), array('/uploads'))) {
        fwrite(STDERR, "FAIL: request was not denied: $uri\n");
        exit(1);
    }
}

$allowed = array(
    '/cms/login.php?next=%2F',
    '/cms/api.php?action=version',
    '/cms/assets/admin.css',
    '/cms/assets/dialog.js',
    '/contented/public.txt',
    '/uploads/photo.png',
);
foreach ($allowed as $uri) {
    if (pagecore_request_is_denied($uri, array('/content'), array('/uploads'))) {
        fwrite(STDERR, "FAIL: public request was denied: $uri\n");
        exit(1);
    }
}

fwrite(STDOUT, "PASS: request guard rejects private, executable, traversal, encoding, and backslash variants\n");
