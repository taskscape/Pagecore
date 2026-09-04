<?php

require dirname(__DIR__) . '/cms/modules/update-archive.php';

$failures = array();
function manifest_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$commit = str_repeat('b', 40);
$identity = array('version' => '2.50.1', 'commit' => $commit);
$root = sys_get_temp_dir() . '/pagecore-manifest-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);

function manifest_files($commit) {
    return array(
        'cms/engine.php' => "<?php\ndefine('PAGECORE_VERSION', '2.50.1');\n",
        'cms/modules/sample.php' => "<?php\nfinal class Sample {}\n",
        'cms/build.json' => json_encode(array(
            'schema' => 1, 'version' => '2.50.1', 'commit' => $commit,
            'commit_time' => '2026-09-06T11:02:44Z', 'channel' => 'main',
        )),
        'VERSION' => "2.50.1\n",
    );
}

/** Build a release-shaped zip; $mutate can corrupt the manifest or the payload. */
function manifest_archive($root, $name, array $files, $commit, ?callable $mutate = null) {
    $entries = array();
    foreach ($files as $path => $contents) {
        $entries[] = array('path' => $path, 'sha256' => hash('sha256', $contents));
    }
    $manifest = array('schema' => 1, 'version' => '2.50.1', 'commit' => $commit, 'files' => $entries);
    if ($mutate !== null) { $mutate($files, $manifest); }
    $archivePath = $root . '/' . $name;
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { return null; }
    foreach ($files as $path => $contents) { $zip->addFromString($path, $contents); }
    $zip->addFromString('manifest.json', json_encode($manifest));
    $zip->close();
    return $archivePath;
}

function manifest_run($root, $label, array $files, $commit, ?callable $mutate = null) {
    $archive = manifest_archive($root, $label . '.zip', $files, $commit, $mutate);
    $tree = $root . '/' . $label . '-tree';
    $extraction = PagecoreUpdateArchive::extract($archive, $tree);
    if (!$extraction->ok) { return array('stage' => 'extract', 'ok' => false, 'error' => $extraction->error); }
    $manifest = PagecoreUpdateArchive::readManifest($tree);
    if ($manifest === null) { return array('stage' => 'manifest', 'ok' => false, 'error' => 'unreadable manifest'); }
    $verification = PagecoreUpdateArchive::verify($tree, $manifest, array('version' => '2.50.1', 'commit' => $commit));
    return array('stage' => 'verify', 'ok' => $verification->ok, 'error' => $verification->error);
}

/* --------------------------------------------------------------- path safety */
manifest_check(PagecoreUpdateFiles::safeRelativePath('cms/engine.php') === 'cms/engine.php', 'A plain relative path was rejected.');
manifest_check(PagecoreUpdateFiles::safeRelativePath('../evil.php') === null, 'A traversal path was accepted.');
manifest_check(PagecoreUpdateFiles::safeRelativePath('cms/../../evil.php') === null, 'An embedded traversal path was accepted.');
manifest_check(PagecoreUpdateFiles::safeRelativePath('/etc/passwd') === null, 'An absolute path was accepted.');
manifest_check(PagecoreUpdateFiles::safeRelativePath('C:/Windows/x.php') === null, 'A drive-letter path was accepted.');
manifest_check(PagecoreUpdateFiles::safeRelativePath('cms\\engine.php') === null, 'A backslash path was accepted.');
manifest_check(PagecoreUpdateFiles::safeRelativePath("cms/en\0gine.php") === null, 'A null byte in a path was accepted.');
manifest_check(PagecoreUpdateFiles::safeRelativePath('cms//engine.php') === null, 'An empty path segment was accepted.');
manifest_check(PagecoreUpdateFiles::safeRelativePath('cms/./engine.php') === null, 'A dot segment was accepted.');

/* ---------------------------------------------------------- extraction scope */
manifest_check(PagecoreUpdateArchive::isExtractable('cms/engine.php'), 'An engine file was excluded from extraction.');
manifest_check(PagecoreUpdateArchive::isExtractable('manifest.json'), 'The manifest was excluded from extraction.');
manifest_check(!PagecoreUpdateArchive::isExtractable('content/posts/hello.md'), 'Live content was inside the extraction scope.');
manifest_check(!PagecoreUpdateArchive::isExtractable('uploads/2026/09/x.png'), 'Live uploads were inside the extraction scope.');

/* ------------------------------------------------------------- happy path */
$result = manifest_run($root, 'valid', manifest_files($commit), $commit);
manifest_check($result['ok'], 'A valid archive failed verification: ' . (string) $result['error']);

// Content and uploads in a release are install seeds and must be left on disk untouched.
$withSeeds = array_merge(manifest_files($commit), array('content/posts/hello.md' => "# hello\n"));
$archive = manifest_archive($root, 'seeds.zip', $withSeeds, $commit);
$seedTree = $root . '/seeds-tree';
PagecoreUpdateArchive::extract($archive, $seedTree);
manifest_check(!is_file($seedTree . '/content/posts/hello.md'), 'A content seed was extracted by the updater.');
manifest_check(is_file($seedTree . '/cms/engine.php'), 'The engine was not extracted.');

/* ------------------------------------------------------------- corruptions */
$result = manifest_run($root, 'tampered', manifest_files($commit), $commit, function (&$files, &$manifest) {
    $files['cms/engine.php'] .= "// injected\n";
});
manifest_check(!$result['ok'] && strpos((string) $result['error'], 'checksum') !== false, 'A tampered file passed verification.');

$result = manifest_run($root, 'extra', manifest_files($commit), $commit, function (&$files, &$manifest) {
    $files['cms/backdoor.php'] = "<?php echo 'x';\n";
});
manifest_check(!$result['ok'] && strpos((string) $result['error'], 'does not list') !== false, 'An unlisted extra file passed verification.');

$result = manifest_run($root, 'missing', manifest_files($commit), $commit, function (&$files, &$manifest) {
    unset($files['cms/modules/sample.php']);
});
manifest_check(!$result['ok'] && strpos((string) $result['error'], 'missing') !== false, 'A missing manifest file passed verification.');

$result = manifest_run($root, 'version', manifest_files($commit), $commit, function (&$files, &$manifest) {
    $manifest['version'] = '9.9.9';
});
manifest_check(!$result['ok'], 'A manifest naming a different version passed verification.');

$result = manifest_run($root, 'commit', manifest_files($commit), $commit, function (&$files, &$manifest) use ($commit) {
    $manifest['commit'] = str_repeat('f', 40);
});
manifest_check(!$result['ok'], 'A manifest naming a different commit passed verification.');

// The stamp inside the archive must describe the build the feed advertised.
$result = manifest_run($root, 'stamp', manifest_files(str_repeat('e', 40)), $commit, null);
manifest_check(!$result['ok'] && strpos((string) $result['error'], 'build stamp') !== false, 'A mismatched build stamp passed verification.');

$result = manifest_run($root, 'noengine', array('VERSION' => "2.50.1\n"), $commit);
manifest_check(!$result['ok'], 'An archive without an engine passed verification.');

/* ------------------------------------------------------ traversal in archive */
$traversal = $root . '/traversal.zip';
$zip = new ZipArchive();
$zip->open($traversal, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('../escaped.php', "<?php echo 'escaped';\n");
$zip->close();
$extraction = PagecoreUpdateArchive::extract($traversal, $root . '/traversal-tree');
manifest_check(!$extraction->ok, 'An archive containing a traversal entry was extracted.');
manifest_check(!is_file($root . '/escaped.php'), 'A traversal entry escaped the extraction directory.');

/* ---------------------------------------------------------------- structure */
manifest_check(PagecoreUpdateArchive::parses($root . '/valid-tree/cms/engine.php'), 'A valid engine did not parse.');
file_put_contents($root . '/broken.php', "<?php function ( {\n");
manifest_check(!PagecoreUpdateArchive::parses($root . '/broken.php'), 'A truncated PHP file was reported as parseable.');
manifest_check(!PagecoreUpdateArchive::parses($root . '/does-not-exist.php'), 'A missing file was reported as parseable.');

PagecoreUpdateFiles::removeTree($root);
if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
fwrite(STDOUT, "PASS: archive verification rejects tampering, traversal, unlisted files, and mismatched build stamps\n");
