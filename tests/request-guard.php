<?php

require dirname(__DIR__) . '/cms/request-guard.php';

$denied = array(
    '/cms/engine.php',
    '/cms/request-guard.php',
    '/cms/README.md',
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

$htaccess = (string) file_get_contents(dirname(__DIR__) . '/cms/.htaccess');
if (strpos($htaccess, '<FilesMatch "\.md$">') === false) {
    fwrite(STDERR, "FAIL: cms/.htaccess must deny Markdown so Apache cannot serve cms/README.md\n");
    exit(1);
}

$readme = (string) file_get_contents(dirname(__DIR__) . '/cms/README.md');
if (stripos($readme, 'legalizm') !== false) {
    fwrite(STDERR, "FAIL: cms/README.md must not print a real-looking password\n");
    exit(1);
}

fwrite(STDOUT, "PASS: request guard rejects private, executable, traversal, encoding, and backslash variants\n");
