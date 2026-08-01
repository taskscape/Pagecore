<?php
require dirname(__DIR__) . '/cms/modules/PathPolicy.php';
require dirname(__DIR__) . '/cms/modules/ContentPolicy.php';
require dirname(__DIR__) . '/cms/modules/SessionContext.php';

$failures = array();
function module_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$root = sys_get_temp_dir() . '/pagecore-module-root';
module_check(PagecorePathPolicy::isWithin($root . '/child/file.md', $root), 'Child path must be contained.');
module_check(!PagecorePathPolicy::isWithin($root . '-sibling/file.md', $root), 'Sibling prefix must not be contained.');
module_check(PagecoreContentPolicy::isPublicStatus(' Publish '), 'Publish status normalization changed.');
module_check(!PagecoreContentPolicy::isPublicStatus('draft'), 'Draft must remain private.');
$page = PagecoreContentPolicy::page(array(1, 2, 3), 9, 2);
module_check($page['page'] === 2 && $page['items'] === array(3) && $page['has_prev'] && !$page['has_next'], 'Pagination contract changed.');
module_check(class_exists('PagecoreSessionContext'), 'Session module did not load independently.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Internal module checks passed.\n";
