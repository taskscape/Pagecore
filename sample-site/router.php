<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__);
require_once $root . '/cms/request-guard.php';
$path = pagecore_request_path($_SERVER['REQUEST_URI']);

if ($path === false || pagecore_request_is_denied(
    $_SERVER['REQUEST_URI'],
    array('/sample-site/config.php', '/sample-site/fixtures', '/sample-site/working-content'),
    array('/sample-site/working-uploads')
)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

if ($path === '/sample-site' || $path === '/sample-site/') {
    require __DIR__ . '/index.php';
    return true;
}

if ($path === '/sample-site/news' || $path === '/sample-site/news/') {
    require __DIR__ . '/news/index.php';
    return true;
}

if ($path === '/sample-site/showcase' || $path === '/sample-site/showcase/') {
    require __DIR__ . '/showcase.php';
    return true;
}

if ($path === '/sample-site/search' || $path === '/sample-site/search/') {
    require __DIR__ . '/search/index.php';
    return true;
}

if (preg_match('~^/sample-site/post/([a-z0-9-]+)/?$~', $path, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/post.php';
    return true;
}

$file = pagecore_public_file($root, $path);
if ($file !== false) {
    return false;
}

http_response_code(404);
echo 'Not found';
return true;
