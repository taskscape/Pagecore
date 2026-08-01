<?php
require dirname(__DIR__) . '/cms/modules/MediaReferences.php';

$failures = array();
function media_reference_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$url = '/cms/media-file.php?path=2026%2F08%2Fphoto.png';
$markdown = "![Photo]($url \"Caption\")\n\npdf:/cms/media-file.php?path=docs%2Fguide.pdf \"Guide\"\n";
media_reference_check(PagecoreMediaReferences::matches($markdown, array($url)), 'Exact image destination was not detected.');
media_reference_check(PagecoreMediaReferences::matches($markdown, array('/cms/media-file.php?path=docs%2Fguide.pdf')), 'Exact PDF destination was not detected.');
media_reference_check(!PagecoreMediaReferences::matches($markdown, array($url . '.backup')), 'Substring-only reference produced a false match.');
media_reference_check(!PagecoreMediaReferences::matches('Plain text ' . $url, array($url)), 'Plain text was incorrectly treated as a declared media reference.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Media reference checks passed.\n";
