<?php
require dirname(__DIR__) . '/cms/modules/ContentCache.php';

$failures = array();
function content_cache_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pagecore-content-cache-' . bin2hex(random_bytes(6));
$posts = $root . DIRECTORY_SEPARATOR . 'posts';
$rendered = $root . DIRECTORY_SEPARATOR . 'rendered';
mkdir($posts, 0775, true);
$post = $posts . DIRECTORY_SEPARATOR . 'hello.md';
file_put_contents($post, "---\ntitle: Hello\n---\nBody\n");

try {
    $manifest = PagecoreContentCache::manifestJson($posts);
    content_cache_check($manifest !== false && PagecoreContentCache::manifestMatches($manifest, $posts), 'Fresh post manifest did not match.');
    file_put_contents($post, "---\ntitle: Changed\n---\nBody changed\n");
    touch($post, time() + 2);
    clearstatcache(true, $post);
    content_cache_check(!PagecoreContentCache::manifestMatches($manifest, $posts), 'An external in-place edit did not invalidate the manifest.');
    $changedManifest = PagecoreContentCache::manifestJson($posts);
    file_put_contents($posts . DIRECTORY_SEPARATOR . 'second.md', 'Second');
    content_cache_check(!PagecoreContentCache::manifestMatches($changedManifest, $posts), 'A newly added post did not invalidate the manifest.');
    unlink($posts . DIRECTORY_SEPARATOR . 'second.md');

    $renderCount = 0;
    $renderer = function ($markdown) use (&$renderCount) { $renderCount++; return '<p>' . strtoupper($markdown) . '</p>'; };
    $writer = function ($path, $html) {
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0775, true); }
        return file_put_contents($path, $html) !== false;
    };
    $uncached = PagecoreContentCache::rendered('hello', false, $rendered, 'renderer-1', 'safe', $renderer, $writer);
    $cached = PagecoreContentCache::rendered('hello', true, $rendered, 'renderer-1', 'safe', $renderer, $writer);
    $hit = PagecoreContentCache::rendered('hello', true, $rendered, 'renderer-1', 'safe', $renderer, $writer);
    content_cache_check($uncached === $cached && $cached === $hit, 'Cached and uncached renderer output differed.');
    content_cache_check($renderCount === 2, 'Stable rendered content did not produce a cache hit.');
    PagecoreContentCache::rendered('hello', true, $rendered, 'renderer-2', 'safe', $renderer, $writer);
    PagecoreContentCache::rendered('hello', true, $rendered, 'renderer-2', 'safe-v2', $renderer, $writer);
    content_cache_check($renderCount === 4, 'Renderer or safety-policy changes did not invalidate cached HTML.');
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($root);
}

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Content cache checks passed.\n";
