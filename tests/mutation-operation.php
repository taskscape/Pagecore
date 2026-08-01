<?php
require dirname(__DIR__) . '/cms/engine.php';

$failures = array();
function check_operation($condition, $message) {
    global $failures;
    if (!$condition) { $failures[] = $message; }
}

$root = sys_get_temp_dir() . '/pagecore-operation-' . bin2hex(random_bytes(5));
$content = $root . '/content';
mkdir($content . '/posts', 0700, true);
mkdir($content . '/pages', 0700, true);
$GLOBALS['CMS_CONFIG']['content_dir'] = $content;
$GLOBALS['CMS_CONFIG']['backup_dir'] = $content . '/.backups';
$GLOBALS['CMS_CONFIG']['site_root'] = $root;
$GLOBALS['CMS_CONFIG']['site_url'] = 'https://example.test';
$GLOBALS['CMS_CONFIG']['search_pages'] = array('/' => array('Home', 'Page', ''));
$GLOBALS['CMS_CONFIG']['categories'] = array('news' => array('News', '/news/'));
file_put_contents($content . '/posts/example.md', "---\ntitle: Example\ndate: 2026-08-01\ncategory: news\n---\nBody\n");

$baseline = cms_regenerate_indexes();
check_operation(!empty($baseline['ok']), 'Baseline artifact generation must succeed.');
$paths = cms_generated_artifact_paths();
$expected = cms_file_snapshot($paths);

foreach (array('posts-index.json', 'search-index.json', 'sitemap.xml') as $artifact) {
    $GLOBALS['PAGECORE_WRITE_FAILURES'] = array($artifact);
    $result = cms_regenerate_indexes();
    check_operation(empty($result['ok']), $artifact . ' failure must be reported.');
    check_operation(cms_file_snapshot($paths) === $expected, $artifact . ' failure must restore every generated artifact.');
}
unset($GLOBALS['PAGECORE_WRITE_FAILURES']);
$repair = cms_regenerate_indexes();
check_operation(!empty($repair['ok']), 'The idempotent repair pass must succeed.');

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $entry) { $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname()); }
@rmdir($root);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "Observable mutation checks passed.\n";
