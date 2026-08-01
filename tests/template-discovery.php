<?php
require dirname(__DIR__) . '/cms/modules/PathPolicy.php';
require dirname(__DIR__) . '/cms/modules/TemplateDiscovery.php';

$failures = array();
function template_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pagecore-template-' . bin2hex(random_bytes(6));
mkdir($root . DIRECTORY_SEPARATOR . 'pages', 0775, true);
file_put_contents($root . DIRECTORY_SEPARATOR . 'home.php', "<?php cms_editable('hero/title'); ?>");
file_put_contents($root . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'about.php', '<div data-cms-key="about/body"></div>');

try {
    $first = PagecoreTemplateDiscovery::discover($root, array('home.php', 'pages'), 20);
    template_check($first['keys'] === array('about/body', 'hero/title'), 'Initial template keys were not discovered accurately.');
    template_check(!$first['truncated'], 'Small bounded discovery was incorrectly reported as truncated.');

    $cached = PagecoreTemplateDiscovery::discover($root, array('home.php', 'pages'), 20, $first['cache']);
    template_check($cached['keys'] === $first['keys'], 'Cached discovery changed stable template keys.');

    $home = $root . DIRECTORY_SEPARATOR . 'home.php';
    file_put_contents($home, "<?php cms_editable('hero/strap'); ?>");
    touch($home, time() + 2);
    clearstatcache(true, $home);
    $changed = PagecoreTemplateDiscovery::discover($root, array('home.php', 'pages'), 20, $first['cache']);
    template_check(in_array('hero/strap', $changed['keys'], true), 'Path/mtime cache did not invalidate after a template edit.');
    template_check(!in_array('hero/title', $changed['keys'], true), 'Invalidated cache retained a removed key.');

    for ($index = 0; $index < 8; $index++) {
        file_put_contents($root . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'fixture-' . $index . '.php', "<div data-cms-key=\"fixture/$index\"></div>");
    }
    $bounded = PagecoreTemplateDiscovery::discover($root, array('pages'), 2);
    template_check($bounded['truncated'], 'Large fixture did not report the configured discovery cap.');
    template_check(count($bounded['cache']['entries']) <= 2, 'Discovery read beyond its configured file cap.');

    $missing = PagecoreTemplateDiscovery::discover($root, array('missing-directory'), 20);
    template_check(count($missing['diagnostics']) === 1, 'Unreadable or missing roots did not produce one deterministic diagnostic.');
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($root);
}

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Template discovery checks passed.\n";
