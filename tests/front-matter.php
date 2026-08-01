<?php
require dirname(__DIR__) . '/cms/modules/FrontMatter.php';

$failures = array();
function front_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }
$source = "---\r\ntitle: \"Example: title\"\r\ndate: 2024-02-29 23:59\r\ncategory: news\r\nstatus: publish\r\ncustom-field: retained\r\n---\r\nBody\r\n--- not a delimiter\r\n";
$parsed = PagecoreFrontMatter::parse($source, array('news'));
front_check($parsed['diagnostics'] === array(), 'Valid metadata produced diagnostics.');
front_check($parsed['meta']['title'] === 'Example: title' && $parsed['meta']['custom-field'] === 'retained', 'Quoted or unknown metadata was not preserved.');
front_check(strpos($parsed['body'], '--- not a delimiter') !== false, 'Non-delimiter body text was truncated.');
$built = PagecoreFrontMatter::build($parsed['meta'], $parsed['body']);
$roundTrip = PagecoreFrontMatter::parse($built, array('news'));
front_check($roundTrip['meta'] === $parsed['meta'] && $roundTrip['body'] === $parsed['body'], 'Parse/build round trip is not deterministic.');
front_check(PagecoreFrontMatter::build($roundTrip['meta'], $roundTrip['body']) === $built, 'Second build changed serialized output.');
foreach (array(
    "---\ntitle: Missing close\nBody" => 'delimiter',
    "---\nbroken line\n---\nBody" => 'Malformed',
    "---\ndate: 2026-02-30\n---\nBody" => 'date',
    "---\nstatus: mystery\n---\nBody" => 'status',
    "---\ncategory: missing\n---\nBody" => 'category',
) as $invalid => $needle) {
    $result = PagecoreFrontMatter::parse($invalid, array('news'));
    front_check(stripos(implode(' ', $result['diagnostics']), $needle) !== false, 'Missing diagnostic for ' . $needle . '.');
}
if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Front-matter checks passed.\n";
