<?php
require dirname(__DIR__) . '/cms/api-registry.php';

$registry = pagecore_api_registry();
$expected = array('get', 'revisions', 'media-list', 'media-impact', 'content-inventory', 'version', 'preview-draft', 'preview', 'save', 'save-draft', 'publish', 'discard-draft', 'restore', 'save-post-meta', 'create-post', 'delete-post', 'save-nav', 'create-region', 'save-media-meta', 'delete-media', 'upload', 'logout');
sort($expected);
$actual = array_keys($registry);
sort($actual);
if ($actual !== $expected) { fwrite(STDERR, "Action registry is incomplete.\n"); exit(1); }
foreach ($registry as $action => $definition) {
    if (!in_array($definition['method'], array('GET', 'POST'), true) || !isset($definition['authorization'], $definition['response'])) {
        fwrite(STDERR, "Invalid registry contract for $action.\n"); exit(1);
    }
}
$source = file_get_contents(dirname(__DIR__) . '/cms/api.php');
if (preg_match('~\\bswitch\\s*\\(~', $source) || preg_match('~\\bcase\\s+[\'\"]~', $source)) {
    fwrite(STDERR, "API control-flow switch remains.\n"); exit(1);
}
foreach ($expected as $action) {
    if (strpos($source, "'$action' => function") === false) { fwrite(STDERR, "Missing callable handler for $action.\n"); exit(1); }
}
echo "API registry checks passed.\n";
