<?php

require dirname(__DIR__) . '/cms/modules/update-installer.php';

$failures = array();
function installer_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$oldCommit = str_repeat('a', 40);
$newCommit = str_repeat('b', 40);
$hosts = array('github.com');
$archiveUrl = 'https://github.com/taskscape/Pagecore/releases/download/build-bbbbbbb/pagecore-2.50.1-bbbbbbb.zip';
$root = sys_get_temp_dir() . '/pagecore-installer-' . bin2hex(random_bytes(6));

function installer_stamp($version, $commit, $time) {
    return json_encode(array('schema' => 1, 'version' => $version, 'commit' => $commit, 'commit_time' => $time, 'channel' => 'main'));
}

/** A minimal but structurally real site: public root with cms/ inside it. */
function installer_site($root, $oldCommit) {
    $site = $root . '/site';
    mkdir($site . '/cms/modules', 0700, true);
    file_put_contents($site . '/cms/engine.php', "<?php\ndefine('PAGECORE_VERSION', '2.49.0');\n// old engine\n");
    file_put_contents($site . '/cms/modules/sample.php', "<?php\nfinal class Sample { const V = 'old'; }\n");
    file_put_contents($site . '/cms/build.json', installer_stamp('2.49.0', $oldCommit, '2026-09-04T06:22:11Z'));
    file_put_contents($site . '/cms/config.php', "<?php return array('site' => 'local');\n");
    return $site;
}

/** Build the published archive; $engineSource lets a test ship a broken engine. */
function installer_archive($root, $name, $newCommit, $engineSource) {
    $files = array(
        'cms/engine.php' => $engineSource,
        'cms/modules/sample.php' => "<?php\nfinal class Sample { const V = 'new'; }\n",
        'cms/build.json' => installer_stamp('2.50.1', $newCommit, '2026-09-06T11:02:44Z'),
        'VERSION' => "2.50.1\n",
    );
    $entries = array();
    foreach ($files as $path => $contents) { $entries[] = array('path' => $path, 'sha256' => hash('sha256', $contents)); }
    $path = $root . '/' . $name;
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $entry => $contents) { $zip->addFromString($entry, $contents); }
    $zip->addFromString('manifest.json', json_encode(array('schema' => 1, 'version' => '2.50.1', 'commit' => $newCommit, 'files' => $entries)));
    $zip->close();
    return $path;
}

function installer_feed($archivePath, $archiveUrl, $newCommit, $hosts, $digestOverride = null) {
    return PagecoreUpdatePolicy::normalizeFeed(array(
        'schema' => 1,
        'channel' => 'main',
        'version' => '2.50.1',
        'commit' => $newCommit,
        'commit_time' => '2026-09-06T11:02:44Z',
        'archive_url' => $archiveUrl,
        'archive_sha256' => $digestOverride === null ? hash_file('sha256', $archivePath) : $digestOverride,
        'archive_bytes' => max(10240, (int) filesize($archivePath)),
        'min_php' => '8.3.0',
    ), $hosts);
}

/** A transport that serves one local archive, so the test performs no egress. */
function installer_transport($archivePath, $hosts) {
    return new PagecoreUpdateTransport(array(
        'allowed_hosts' => $hosts,
        'max_bytes' => 26214400,
        'fetcher' => function ($url, $headers, $sink) use ($archivePath) {
            $bytes = (string) file_get_contents($archivePath);
            if ($sink) { fwrite($sink, $bytes); }
            return array('status' => 200, 'headers' => array(), 'body' => $sink ? '' : $bytes, 'bytes' => strlen($bytes));
        },
    ));
}

function installer_make($site, $root, $archivePath, $hosts, $apply = true) {
    return new PagecoreUpdateInstaller(array(
        'site_root' => $site,
        'state_dir' => $root . '/private/state',
        'work_dir' => $root . '/private/updates',
        'apply' => $apply,
        'keep' => 2,
        'preserve' => array('config.php'),
        'allowed_hosts' => $hosts,
        'transport' => installer_transport($archivePath, $hosts),
    ));
}

$goodEngine = "<?php\ndefine('PAGECORE_VERSION', '2.50.1');\n// new engine\n";
$brokenEngine = "<?php\nfunction broken( {\n";

/* ------------------------------------------------------------- happy path */
mkdir($root, 0700, true);
$site = installer_site($root, $oldCommit);
$archive = installer_archive($root, 'release.zip', $newCommit, $goodEngine);
$feed = installer_feed($archive, $archiveUrl, $newCommit, $hosts);
$installed = PagecoreUpdatePolicy::normalizeBuild(json_decode(file_get_contents($site . '/cms/build.json'), true));

installer_check($feed !== null && $installed !== null, 'The test fixtures did not normalize.');
$outcome = installer_make($site, $root, $archive, $hosts)->apply($feed, $installed);
installer_check($outcome->ok && $outcome->status === 'applied', 'A valid update did not apply: ' . $outcome->message);
installer_check(strpos((string) file_get_contents($site . '/cms/engine.php'), 'new engine') !== false, 'The engine was not replaced.');
installer_check(strpos((string) file_get_contents($site . '/cms/modules/sample.php'), "'new'") !== false, 'A nested module was not replaced.');
installer_check(file_get_contents($site . '/cms/config.php') === "<?php return array('site' => 'local');\n", 'The local configuration was not preserved.');
installer_check(is_file($site . '/cms/build.json'), 'The new build stamp is missing.');

$leftovers = array_filter((array) scandir($site), function ($entry) { return strncmp($entry, 'cms.', 4) === 0; });
installer_check($leftovers === array(), 'Staging or retired directories were left in the site root.');
installer_check(!PagecoreUpdateState::maintenanceActive($root . '/private/state'), 'The maintenance flag was not cleared.');

$snapshots = installer_make($site, $root, $archive, $hosts)->snapshots();
installer_check(count($snapshots) === 1 && $snapshots[0]['version'] === '2.49.0', 'A rollback snapshot of the previous engine was not kept.');
installer_check(strpos((string) file_get_contents($snapshots[0]['path'] . '/engine.php'), 'old engine') !== false, 'The snapshot does not hold the previous engine.');
PagecoreUpdateFiles::removeTree($root);

/* ------------------------------------------------ checksum mismatch aborts */
mkdir($root, 0700, true);
$site = installer_site($root, $oldCommit);
$archive = installer_archive($root, 'release.zip', $newCommit, $goodEngine);
$badFeed = installer_feed($archive, $archiveUrl, $newCommit, $hosts, str_repeat('d', 64));
$outcome = installer_make($site, $root, $archive, $hosts)->apply($badFeed, $installed);
installer_check(!$outcome->ok && $outcome->stage === 'download', 'A checksum mismatch was not caught at download.');
installer_check(strpos((string) file_get_contents($site . '/cms/engine.php'), 'old engine') !== false, 'A failed download modified the live engine.');
PagecoreUpdateFiles::removeTree($root);

/* --------------------------------------------- postcheck triggers rollback */
mkdir($root, 0700, true);
$site = installer_site($root, $oldCommit);
// Hashes match the manifest, so verification passes; only the structural
// postcheck after the swap can catch this, which must roll the swap back.
$archive = installer_archive($root, 'broken.zip', $newCommit, $brokenEngine);
$feed = installer_feed($archive, $archiveUrl, $newCommit, $hosts);
$outcome = installer_make($site, $root, $archive, $hosts)->apply($feed, $installed);
installer_check(!$outcome->ok && $outcome->stage === 'postcheck', 'A broken engine was not caught after the swap: ' . $outcome->stage);
installer_check(strpos((string) file_get_contents($site . '/cms/engine.php'), 'old engine') !== false, 'The previous engine was not restored.');
installer_check(file_get_contents($site . '/cms/config.php') === "<?php return array('site' => 'local');\n", 'Rollback lost the local configuration.');
$leftovers = array_filter((array) scandir($site), function ($entry) { return strncmp($entry, 'cms.', 4) === 0; });
installer_check($leftovers === array(), 'Rollback left staging directories behind.');
installer_check(!PagecoreUpdateState::maintenanceActive($root . '/private/state'), 'Rollback left the maintenance flag set.');
PagecoreUpdateFiles::removeTree($root);

/* ------------------------------------------------------- disabled and busy */
mkdir($root, 0700, true);
$site = installer_site($root, $oldCommit);
$archive = installer_archive($root, 'release.zip', $newCommit, $goodEngine);
$feed = installer_feed($archive, $archiveUrl, $newCommit, $hosts);

$outcome = installer_make($site, $root, $archive, $hosts, false)->apply($feed, $installed);
installer_check(!$outcome->ok && $outcome->status === 'blocked', 'An update ran while update_apply was disabled.');
installer_check(strpos((string) file_get_contents($site . '/cms/engine.php'), 'old engine') !== false, 'A disabled instance still modified its engine.');

$lock = PagecoreUpdateState::lock($root . '/private/state');
installer_check($lock !== null, 'The update lock could not be taken.');
$outcome = installer_make($site, $root, $archive, $hosts)->apply($feed, $installed);
installer_check(!$outcome->ok && $outcome->status === 'busy', 'A second concurrent update was not refused.');
PagecoreUpdateState::unlock($lock);

$outcome = installer_make($site, $root, $archive, $hosts)->apply($feed, $installed);
installer_check($outcome->ok, 'The update did not proceed once the lock was released.');
PagecoreUpdateFiles::removeTree($root);

/* -------------------------------------------------- post-swap code loading */
// Nothing the installer needs may be loaded after the directory swap.
installer_check(PagecoreUpdateInstaller::warm(), 'Installer dependencies were not all loaded before the swap.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
fwrite(STDOUT, "PASS: installer swaps atomically, preserves configuration, and rolls back a failed postcheck\n");
