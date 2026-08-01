<?php
require dirname(__DIR__) . '/scripts/lib/WordPressSqlDump.php';
require dirname(__DIR__) . '/scripts/lib/WordPressImportPolicy.php';
require dirname(__DIR__) . '/scripts/lib/WordPressHtmlConverter.php';

function sql_fields($row) { return PagecoreWordPressSqlDump::fields($row); }
function sql_val($value) { return PagecoreWordPressSqlDump::value($value); }
function wp_unserialize_option($value) { return PagecoreWordPressImportPolicy::decodeSerializedOption($value); }
require dirname(__DIR__) . '/scripts/lib/WordPressMenu.php';

$failures = array();
function import_component_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$sql = "INSERT INTO `wp_posts` VALUES (1,'Zażółć, gęślą','line\\nnext',NULL);\n";
$rows = PagecoreWordPressSqlDump::rows($sql, 'wp_posts');
$fields = PagecoreWordPressSqlDump::fields($rows[0]);
import_component_check(count($rows) === 1 && count($fields) === 4, 'Escaped/multibyte SQL tuple was split incorrectly.');
import_component_check(PagecoreWordPressSqlDump::value($fields[1]) === 'Zażółć, gęślą', 'Multibyte SQL field changed.');
import_component_check(PagecoreWordPressSqlDump::value($fields[2]) === "line\nnext", 'Escaped newline was not decoded.');
import_component_check(PagecoreWordPressSqlDump::value($fields[3]) === null, 'SQL NULL contract changed.');
$malformedRejected = false;
try { PagecoreWordPressSqlDump::fields("'unterminated"); } catch (RuntimeException $error) { $malformedRejected = true; }
import_component_check($malformedRejected, 'Malformed SQL field was accepted.');

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pagecore-sql-' . bin2hex(random_bytes(6)) . '.sql';
file_put_contents($temp, "\xEF\xBB\xBF" . $sql);
try {
    $streamed = PagecoreWordPressSqlDump::rowsFromFile($temp, array('wp_posts'), 4096);
    import_component_check(count($streamed['wp_posts']) === 1, 'BOM-prefixed dump did not stream.');
} finally { unlink($temp); }

$converter = new PagecoreWordPressHtmlConverter('/media');
$markdown = $converter->convert('<h2>Zażółć</h2><ul><li>One</li><li>Two</li></ul><pre><code>&lt;x&gt;</code></pre>'
    . '<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>'
    . '<iframe src="https://video.example/watch"></iframe><script>alert(1)</script>'
    . '<a href="javascript:alert(2)">bad</a><img src="https://old.example/wp-content/uploads/2026/pic.jpg" alt="Pic">');
foreach (array('## Zażółć', '- One', '```', '| A | B |', 'https://video.example/watch', '/media/2026/pic.jpg') as $expected) {
    import_component_check(strpos($markdown, $expected) !== false, 'Converter fixture lost: ' . $expected);
}
import_component_check(stripos($markdown, 'javascript:') === false && stripos($markdown, '<iframe') === false, 'Unsafe active content survived conversion.');
import_component_check(in_array('active:iframe:linked', $converter->diagnostics(), true), 'Linked active node was not reported.');
import_component_check(in_array('active:script:dropped', $converter->diagnostics(), true), 'Dropped active node was not reported.');

import_component_check(PagecoreWordPressImportPolicy::uploadRelativePath('../secret.txt') === null, 'Upload traversal was accepted.');
import_component_check(PagecoreWordPressImportPolicy::uploadRelativePath('2026/08/photo.jpg') === '2026/08/photo.jpg', 'Safe upload path changed.');
import_component_check(PagecoreWordPressImportPolicy::decodeSerializedOption('a:1:{s:7:"primary";i:4;}')['primary'] === 4, 'Serialized option was not decoded.');
import_component_check(PagecoreWordPressImportPolicy::decodeSerializedOption('not serialized') === null, 'Malformed serialized option was accepted.');
$seen = array();
import_component_check(PagecoreWordPressImportPolicy::uniqueSlug('same', $seen) === 'same'
    && PagecoreWordPressImportPolicy::uniqueSlug('same', $seen) === 'same-2', 'Duplicate slug planning changed.');
import_component_check(PagecoreWordPressMenu\menu_internal_url('https://example.test/blog/news/?p=1#top', array('https://example.test')) === '/news/?p=1#top', 'Same-site menu URL was not normalized.');
import_component_check(PagecoreWordPressMenu\imported_nav_items(array(), array(), array(), array(), array(), array(), '/post/{slug}/') === array(), 'Empty menu plan was not deterministic.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "WordPress import component checks passed.\n";
