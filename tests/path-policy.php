<?php
require dirname(__DIR__) . '/cms/modules/PathPolicy.php';

$failures = array();
function path_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }
$base = sys_get_temp_dir() . '/pagecore-path-' . bin2hex(random_bytes(4));
$root = $base . '/root';
$outside = $base . '/root-sibling';
mkdir($root . '/inside', 0700, true);
mkdir($outside, 0700, true);
file_put_contents($root . '/inside/file.md', 'inside');
file_put_contents($outside . '/outside.md', 'outside');

path_check(PagecorePathPolicy::isWithin($root . '/inside/file.md', $root), 'Existing child was rejected.');
path_check(!PagecorePathPolicy::isWithin($outside . '/outside.md', $root), 'Shared-prefix sibling was accepted.');
path_check(PagecorePathPolicy::resolveWithin($root, 'inside/new.md', false) !== null, 'Non-existent leaf was rejected.');
path_check(PagecorePathPolicy::resolveWithin($root, '../root-sibling/outside.md', true) === null, 'Traversal was accepted.');
path_check(PagecorePathPolicy::resolveWithin($root, 'inside%2Ffile.md', true) === null, 'Encoded separator was accepted.');
path_check(PagecorePathPolicy::resolveWithin($root, 'C:\\outside.md', false) === null, 'Drive-absolute path was accepted.');
path_check(!PagecorePathPolicy::isWithin('//server/share-two/file', '//server/share'), 'UNC shared-prefix sibling was accepted.');
if (DIRECTORY_SEPARATOR === '\\') {
    path_check(PagecorePathPolicy::isWithin(strtoupper($root . '/inside'), strtolower($root)), 'Windows case normalization changed.');
}
$link = $root . '/outside-link';
if (@symlink($outside, $link)) {
    path_check(PagecorePathPolicy::resolveWithin($root, 'outside-link/outside.md', true) === null, 'Escaping symlink was accepted.');
    @unlink($link);
}

@unlink($root . '/inside/file.md'); @rmdir($root . '/inside'); @rmdir($root);
@unlink($outside . '/outside.md'); @rmdir($outside); @rmdir($base);
if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Path policy checks passed.\n";
