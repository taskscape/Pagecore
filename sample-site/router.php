<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rawurldecode($uri);
$root = dirname(__DIR__);

if (preg_match('~^/sample-site/(working-content|fixtures)(/|$)~', $path)
    || $path === '/sample-site/config.php'
    || preg_match('~^/sample-site/working-uploads/.*\.php$~i', $path)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

if (preg_match('~^/cms/(config|engine|auth)\.php$~', $path)
    || preg_match('~^/cms/lib(/|$)~', $path)) {
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

$file = realpath($root . str_replace('/', DIRECTORY_SEPARATOR, $path));
if ($file !== false && strpos(str_replace('\\', '/', $file), str_replace('\\', '/', $root) . '/') === 0 && is_file($file)) {
    return false;
}

http_response_code(404);
echo 'Not found';
return true;
