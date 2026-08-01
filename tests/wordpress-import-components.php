<?php
require dirname(__DIR__) . '/scripts/lib/WordPressSqlDump.php';
require dirname(__DIR__) . '/scripts/lib/WordPressImportPolicy.php';
require dirname(__DIR__) . '/scripts/lib/WordPressHtmlConverter.php';
require dirname(__DIR__) . '/scripts/lib/WordPressShortcodes.php';

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

// phpMyAdmin exports declare an explicit column list; positional readers still
// require canonical WordPress schema order.
$schemas = PagecoreWordPressSqlDump::wordPressSchemas('wp_');
import_component_check($schemas['wp_posts'][20] === 'post_type' && $schemas['wp_posts'][4] === 'post_content',
    'Canonical wp_posts column order changed.');
$declaredSql = "INSERT INTO `wp_posts` (`ID`, `post_type`, `post_title`, `post_content`) VALUES\n"
    . "(7,'page','Kontakt','Body, with comma'),\n(8,'post','Wpis','Drugi');\n";
$declaredRows = PagecoreWordPressSqlDump::rows($declaredSql, 'wp_posts', $schemas['wp_posts']);
import_component_check(count($declaredRows) === 2, 'Column-list INSERT did not yield every tuple.');
$declaredFields = PagecoreWordPressSqlDump::fields($declaredRows[0]);
import_component_check(count($declaredFields) === count($schemas['wp_posts']), 'Remapped tuple is not canonical width.');
import_component_check(sql_val($declaredFields[0]) === '7'
    && sql_val($declaredFields[20]) === 'page'
    && sql_val($declaredFields[5]) === 'Kontakt'
    && sql_val($declaredFields[4]) === 'Body, with comma',
    'Declared columns were not remapped into canonical positions.');
import_component_check(sql_val($declaredFields[7]) === '', 'Omitted column did not become an empty field.');
// Extra site-specific columns (Yoast blog_id) must be ignored, not shift positions.
$yoastRows = PagecoreWordPressSqlDump::rows(
    "INSERT INTO `wp_yoast_primary_term` (`id`, `post_id`, `term_id`, `taxonomy`, `created_at`, `updated_at`, `blog_id`) VALUES (1,42,9,'category','x','y',1);\n",
    'wp_yoast_primary_term', $schemas['wp_yoast_primary_term']);
$yoastFields = PagecoreWordPressSqlDump::fields($yoastRows[0]);
import_component_check(sql_val($yoastFields[1]) === '42' && sql_val($yoastFields[2]) === '9' && sql_val($yoastFields[3]) === 'category',
    'Unknown trailing column shifted canonical positions.');
$mismatchRejected = false;
try {
    PagecoreWordPressSqlDump::rows("INSERT INTO `wp_terms` (`term_id`, `name`) VALUES (1,'A','extra');\n", 'wp_terms', $schemas['wp_terms']);
} catch (RuntimeException $error) { $mismatchRejected = true; }
import_component_check($mismatchRejected, 'Tuple wider than the declared column list was accepted.');
$declaredTemp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pagecore-sql-cols-' . bin2hex(random_bytes(6)) . '.sql';
file_put_contents($declaredTemp, $declaredSql);
try {
    $streamedDeclared = PagecoreWordPressSqlDump::rowsFromFile($declaredTemp, array('wp_posts'), 8192, $schemas);
    import_component_check(count($streamedDeclared['wp_posts']) === 2, 'Column-list dump did not stream every tuple.');
    $streamedFields = PagecoreWordPressSqlDump::fields($streamedDeclared['wp_posts'][1]);
    import_component_check(sql_val($streamedFields[20]) === 'post' && sql_val($streamedFields[5]) === 'Wpis',
        'Streamed column-list dump lost canonical order.');
} finally { unlink($declaredTemp); }

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
// Page builders (WPBakery/Qode) append asset ids to upload URLs.
import_component_check(PagecoreWordPressImportPolicy::uploadRelativePath('2019/11/photo.jpg?id=949') === '2019/11/photo.jpg',
    'Query string was treated as part of the upload filename.');
import_component_check(PagecoreWordPressImportPolicy::uploadRelativePath('2019/11/photo.jpg#anchor') === '2019/11/photo.jpg',
    'Fragment was treated as part of the upload filename.');
import_component_check(PagecoreWordPressImportPolicy::uploadRelativePath('?id=949') === null, 'Query-only upload reference was accepted.');
import_component_check(PagecoreWordPressImportPolicy::uploadRelativePath('2019/%2e%2e/secret.txt?id=1') === null,
    'Encoded traversal survived query trimming.');
$internal = PagecoreWordPressImportPolicy::rewriteInternalUrls(
    '<a href="https://example.test/polityka/">A</a> <a href="https://www.example.test/b/">B</a>'
    . ' <a href="https://example.test">Home</a> <a href="https://other.test/keep/">C</a>',
    array('https://example.test'));
import_component_check(strpos($internal, 'href="/polityka/"') !== false
    && strpos($internal, 'href="/b/"') !== false
    && strpos($internal, 'href="/"') !== false, 'Same-site URLs were not made root-relative.');
import_component_check(strpos($internal, 'https://other.test/keep/') !== false, 'A third-party URL was rewritten.');
import_component_check(PagecoreWordPressImportPolicy::rewriteInternalUrls('https://example.testing/x', array('https://example.test'))
    === 'https://example.testing/x', 'A lookalike host was rewritten.');

$rewritten = PagecoreWordPressImportPolicy::rewriteUploads(
    '<img src="https://old.example/wp-content/uploads/2019/11/photo.jpg?id=949"> [x](/wp-content/uploads/2019/12/doc.pdf?id=7)', '/uploads');
import_component_check(strpos($rewritten, '/uploads/2019/11/photo.jpg"') !== false
    && strpos($rewritten, '/uploads/2019/12/doc.pdf)') !== false
    && strpos($rewritten, 'id=949') === false && strpos($rewritten, 'id=7') === false,
    'Upload rewrite kept page-builder query strings.');
import_component_check(PagecoreWordPressImportPolicy::decodeSerializedOption('a:1:{s:7:"primary";i:4;}')['primary'] === 4, 'Serialized option was not decoded.');
import_component_check(PagecoreWordPressImportPolicy::decodeSerializedOption('not serialized') === null, 'Malformed serialized option was accepted.');
$seen = array();
import_component_check(PagecoreWordPressImportPolicy::uniqueSlug('same', $seen) === 'same'
    && PagecoreWordPressImportPolicy::uniqueSlug('same', $seen) === 'same-2', 'Duplicate slug planning changed.');
import_component_check(PagecoreWordPressMenu\menu_internal_url('https://example.test/blog/news/?p=1#top', array('https://example.test')) === '/news/?p=1#top', 'Same-site menu URL was not normalized.');
import_component_check(PagecoreWordPressMenu\imported_nav_items(array(), array(), array(), array(), array(), array(), '/post/{slug}/') === array(), 'Empty menu plan was not deterministic.');

/* ------------------------------------------- page-builder shortcode expansion */
$builder = new PagecoreWordPressShortcodes('/uploads', array('3219' => '2019/11/anna.jpg'));
$builderHtml = $builder->toHtml(
    '[vc_row css=".vc_custom_1{padding-top: 110px !important;}"][vc_column width="1/2"]'
    . '[mindcare_core_section_title title_tag="h1" title="Anna Drabek" tagline="Coach"'
    . ' text="<p style=``color: #555;``>Cytat</p>"]'
    . '[vc_single_image image="3219" img_size="450x450"]'
    . '[vc_column_text]Pierwszy akapit.[/vc_column_text]'
    . '[vc_empty_space height="20px"][rev_slider alias="main-home"]'
    . '[vc_column_text]<strong>Drugi</strong> akapit.[/vc_column_text]'
    . '[/vc_column][/vc_row]');
foreach (array('<h1>Anna Drabek</h1>', '<em>Coach</em>', 'style="color: #555;"', 'Cytat',
    '/uploads/2019/11/anna.jpg', 'Pierwszy akapit.', '<strong>Drugi</strong>') as $expected) {
    import_component_check(strpos($builderHtml, $expected) !== false, 'Builder expansion lost: ' . $expected);
}
import_component_check(strpos($builderHtml, '[vc_') === false && strpos($builderHtml, '[mindcare') === false,
    'Builder markup survived expansion.');
import_component_check(strpos($builderHtml, 'rev_slider') === false && strpos($builderHtml, 'vc_custom_1') === false,
    'Runtime-only shortcode or its CSS attribute leaked into content.');
import_component_check(in_array('dropped:rev_slider', $builder->diagnostics(), true), 'Dropped shortcode was not reported.');
// Editorial text in square brackets is not builder markup and must survive.
$literal = $builder->toHtml('[vc_column_text][ADRES] Wrocław[/vc_column_text]');
import_component_check(strpos($literal, '[ADRES] Wrocław') !== false, 'Literal bracketed text was treated as a shortcode.');
// Expanded builder output still passes through the HTML allowlist.
$builderMarkdown = (new PagecoreWordPressHtmlConverter('/uploads'))->convert($builder->toHtml(
    '[vc_column_text]<script>alert(1)</script><p>Bezpieczny <a href="javascript:alert(2)">tekst</a></p>[/vc_column_text]'));
import_component_check(stripos($builderMarkdown, '<script') === false && stripos($builderMarkdown, 'javascript:') === false,
    'Active markup inside a shortcode survived the allowlist.');
import_component_check(strpos($builderMarkdown, 'Bezpieczny') !== false, 'Safe text inside a shortcode was lost.');
// Base64 payloads decode, and their embeds degrade to links rather than iframes.
$mapMarkdown = (new PagecoreWordPressHtmlConverter('/uploads'))->convert($builder->toHtml(
    '[vc_gmaps link="#E-8_' . base64_encode(rawurlencode('<iframe src="https://maps.example/embed?q=Wroclaw"></iframe>')) . '"]'));
import_component_check(strpos($mapMarkdown, 'https://maps.example/embed?q=Wroclaw') !== false
    && stripos($mapMarkdown, '<iframe') === false, 'Encoded map embed did not degrade to a safe link.');
$rawMarkdown = $builder->toHtml('[vc_raw_html]' . base64_encode(rawurlencode('<p>Zakodowany</p>')) . '[/vc_raw_html]');
import_component_check(strpos($rawMarkdown, '<p>Zakodowany</p>') !== false, 'vc_raw_html payload did not decode.');
// Unbalanced builder markup must not swallow the remaining content.
$unbalanced = $builder->toHtml('[vc_row][vc_column]Treść[/vc_column]');
import_component_check(strpos($unbalanced, 'Treść') !== false && strpos($unbalanced, '[vc_row]') === false,
    'Unclosed builder wrapper lost its content.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "WordPress import component checks passed.\n";
