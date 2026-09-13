<?php
require_once __DIR__ . '/json-policy.php';
require_once __DIR__ . '/update-files.php';
require_once __DIR__ . '/update-policy.php';

final class PagecoreUpdateArchiveResult {
    public $ok;
    public $error;
    public $files;
    public function __construct($ok, $error = null, array $files = array()) {
        $this->ok = (bool) $ok;
        $this->error = $error;
        $this->files = $files;
    }
}

/**
 * Release archive extraction and manifest verification.
 *
 * Only the paths an update actually replaces are extracted — `cms/` plus the
 * top-level `manifest.json` and `VERSION`. The `content/` and `uploads/` trees
 * in a release are install seeds, and an update must never touch live data.
 */
final class PagecoreUpdateArchive {
    const MAX_ENTRIES = 4000;
    const MAX_TOTAL_BYTES = 67108864;
    const MAX_RATIO = 200;
    const EXTRACT_PREFIX = 'cms/';

    public static function isExtractable($relative) {
        return $relative === 'manifest.json'
            || $relative === 'VERSION'
            || strncmp($relative, self::EXTRACT_PREFIX, strlen(self::EXTRACT_PREFIX)) === 0;
    }

    public static function available() {
        return class_exists('ZipArchive') || class_exists('PharData');
    }

    /** Extract the replaceable subset of an archive into an empty directory. */
    public static function extract($archivePath, $targetDirectory) {
        if (!is_file($archivePath)) { return new PagecoreUpdateArchiveResult(false, 'The downloaded archive is missing.'); }
        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            return new PagecoreUpdateArchiveResult(false, 'Could not create the extraction directory.');
        }
        if (substr($archivePath, -4) === '.zip') {
            if (!class_exists('ZipArchive')) { return new PagecoreUpdateArchiveResult(false, 'This server has no ZipArchive support.'); }
            return self::extractZip($archivePath, $targetDirectory);
        }
        if (!class_exists('PharData')) { return new PagecoreUpdateArchiveResult(false, 'This server has no PharData support.'); }
        return self::extractTar($archivePath, $targetDirectory);
    }

    private static function extractZip($archivePath, $targetDirectory) {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) { return new PagecoreUpdateArchiveResult(false, 'The downloaded archive could not be opened.'); }
        $count = $zip->numFiles;
        if ($count > self::MAX_ENTRIES) {
            $zip->close();
            return new PagecoreUpdateArchiveResult(false, 'The archive declares too many entries.');
        }
        $written = array();
        $total = 0;
        for ($index = 0; $index < $count; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['name'])) { continue; }
            $name = (string) $stat['name'];
            if (substr($name, -1) === '/') { continue; }

            $relative = PagecoreUpdateFiles::safeRelativePath($name);
            if ($relative === null) {
                $zip->close();
                return new PagecoreUpdateArchiveResult(false, 'The archive contains an unsafe path.');
            }
            if (!self::isExtractable($relative)) { continue; }
            if (self::isSymlinkEntry($zip, $index)) {
                $zip->close();
                return new PagecoreUpdateArchiveResult(false, 'The archive contains a symbolic link.');
            }
            $size = isset($stat['size']) ? (int) $stat['size'] : 0;
            $compressed = isset($stat['comp_size']) ? (int) $stat['comp_size'] : 0;
            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                $zip->close();
                return new PagecoreUpdateArchiveResult(false, 'The archive expands beyond the permitted size.');
            }
            if ($compressed > 0 && $size / $compressed > self::MAX_RATIO) {
                $zip->close();
                return new PagecoreUpdateArchiveResult(false, 'The archive has an implausible compression ratio.');
            }

            $destination = $targetDirectory . '/' . $relative;
            $directory = dirname($destination);
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                $zip->close();
                return new PagecoreUpdateArchiveResult(false, 'Could not create a directory while extracting.');
            }
            $source = $zip->getStream($name);
            if (!$source) {
                $zip->close();
                return new PagecoreUpdateArchiveResult(false, 'Could not read an archive entry.');
            }
            $sink = @fopen($destination, 'w+b');
            if (!$sink) {
                fclose($source);
                $zip->close();
                return new PagecoreUpdateArchiveResult(false, 'Could not write an extracted file.');
            }
            stream_copy_to_stream($source, $sink);
            fclose($source);
            fclose($sink);
            $written[] = $relative;
        }
        $zip->close();
        sort($written);
        return new PagecoreUpdateArchiveResult(true, null, $written);
    }

    private static function isSymlinkEntry(ZipArchive $zip, $index) {
        $opsys = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) { return false; }
        if ($opsys !== ZipArchive::OPSYS_UNIX) { return false; }
        return (($attributes >> 16) & 0xF000) === 0xA000;
    }

    private static function extractTar($archivePath, $targetDirectory) {
        try {
            $archive = new PharData($archivePath);
            $wanted = array();
            $total = 0;
            $count = 0;
            foreach (new RecursiveIteratorIterator($archive) as $file) {
                $count++;
                if ($count > self::MAX_ENTRIES) { return new PagecoreUpdateArchiveResult(false, 'The archive declares too many entries.'); }
                $relative = PagecoreUpdateFiles::safeRelativePath(self::tarRelativePath($file->getPathname(), $archivePath));
                if ($relative === null) { return new PagecoreUpdateArchiveResult(false, 'The archive contains an unsafe path.'); }
                if (!self::isExtractable($relative)) { continue; }
                $total += (int) $file->getSize();
                if ($total > self::MAX_TOTAL_BYTES) { return new PagecoreUpdateArchiveResult(false, 'The archive expands beyond the permitted size.'); }
                $wanted[] = $relative;
            }
            if (!$wanted) { return new PagecoreUpdateArchiveResult(false, 'The archive contains no updatable files.'); }
            $archive->extractTo($targetDirectory, $wanted, true);
        } catch (Throwable $error) {
            return new PagecoreUpdateArchiveResult(false, 'The downloaded archive could not be expanded.');
        }
        sort($wanted);
        return new PagecoreUpdateArchiveResult(true, null, $wanted);
    }

    private static function tarRelativePath($pathname, $archivePath) {
        $marker = 'phar://';
        if (strncmp($pathname, $marker, strlen($marker)) !== 0) { return $pathname; }
        $normalized = str_replace('\\', '/', $pathname);
        $archiveName = str_replace('\\', '/', $archivePath);
        $position = strpos($normalized, $archiveName);
        return $position === false
            ? ltrim((string) strstr(substr($normalized, strlen($marker)), '/'), '/')
            : ltrim(substr($normalized, $position + strlen($archiveName)), '/');
    }

    public static function readManifest($treeDirectory) {
        $path = $treeDirectory . '/manifest.json';
        if (!is_file($path)) { return null; }
        $decoded = PagecoreJsonPolicy::decodeObject((string) @file_get_contents($path));
        if (!$decoded->ok) { return null; }
        $manifest = $decoded->value;
        if (!isset($manifest['schema']) || (int) $manifest['schema'] !== 1) { return null; }
        if (!isset($manifest['version']) || !PagecoreUpdatePolicy::isVersion($manifest['version'])) { return null; }
        if (!isset($manifest['files']) || !is_array($manifest['files'])) { return null; }
        $files = array();
        foreach ($manifest['files'] as $entry) {
            if (!is_array($entry) || !isset($entry['path'], $entry['sha256'])) { return null; }
            $relative = PagecoreUpdateFiles::safeRelativePath($entry['path']);
            if ($relative === null) { return null; }
            if (!is_string($entry['sha256']) || preg_match('~^[0-9a-f]{64}$~', $entry['sha256']) !== 1) { return null; }
            $files[$relative] = $entry['sha256'];
        }
        if (!$files) { return null; }
        return array(
            'version' => $manifest['version'],
            'commit' => isset($manifest['commit']) && PagecoreUpdatePolicy::isCommit($manifest['commit']) ? $manifest['commit'] : null,
            'files' => $files,
        );
    }

    /**
     * Every extracted file must appear in the manifest with a matching digest,
     * and every manifest entry within the extracted subset must be present.
     */
    public static function verify($treeDirectory, array $manifest, array $expectedIdentity) {
        if (!PagecoreUpdatePolicy::isVersion($manifest['version']) || $manifest['version'] !== $expectedIdentity['version']) {
            return new PagecoreUpdateArchiveResult(false, 'The archive manifest does not match the published version.');
        }
        if ($manifest['commit'] !== null && $manifest['commit'] !== $expectedIdentity['commit']) {
            return new PagecoreUpdateArchiveResult(false, 'The archive manifest does not match the published commit.');
        }

        $present = PagecoreUpdateFiles::listTree($treeDirectory);
        $checked = 0;
        foreach ($present as $relative) {
            if ($relative === 'manifest.json') { continue; }
            if (!isset($manifest['files'][$relative])) {
                return new PagecoreUpdateArchiveResult(false, 'The archive contains a file the manifest does not list: ' . $relative);
            }
            $digest = @hash_file('sha256', $treeDirectory . '/' . $relative);
            if (!is_string($digest) || !hash_equals($manifest['files'][$relative], $digest)) {
                return new PagecoreUpdateArchiveResult(false, 'A file in the archive failed its checksum: ' . $relative);
            }
            $checked++;
        }
        foreach ($manifest['files'] as $relative => $digest) {
            if (!self::isExtractable($relative) || $relative === 'manifest.json') { continue; }
            if (!in_array($relative, $present, true)) {
                return new PagecoreUpdateArchiveResult(false, 'The archive is missing a file the manifest lists: ' . $relative);
            }
        }
        if ($checked === 0) { return new PagecoreUpdateArchiveResult(false, 'The archive contained nothing to verify.'); }

        $engine = $treeDirectory . '/cms/engine.php';
        if (!is_file($engine)) { return new PagecoreUpdateArchiveResult(false, 'The archive does not contain a Pagecore engine.'); }
        $stamp = self::readBuildStamp($treeDirectory . '/cms/build.json');
        if ($stamp === null || $stamp['commit'] !== $expectedIdentity['commit'] || $stamp['version'] !== $expectedIdentity['version']) {
            return new PagecoreUpdateArchiveResult(false, 'The archive build stamp does not match the published build.');
        }
        return new PagecoreUpdateArchiveResult(true, null, $present);
    }

    public static function readBuildStamp($path) {
        if (!is_file($path)) { return null; }
        $decoded = PagecoreJsonPolicy::decodeObject((string) @file_get_contents($path));
        return $decoded->ok ? PagecoreUpdatePolicy::normalizeBuild($decoded->value) : null;
    }

    /** Cheap structural proof that a PHP file survived the copy intact. */
    public static function parses($path) {
        $source = @file_get_contents($path);
        if (!is_string($source) || $source === '') { return false; }
        try { $tokens = @token_get_all($source, TOKEN_PARSE); }
        catch (Throwable $error) { return false; }
        return is_array($tokens) && count($tokens) > 1;
    }
}
