<?php
require_once __DIR__ . '/update-archive.php';
require_once __DIR__ . '/update-files.php';
require_once __DIR__ . '/update-policy.php';
require_once __DIR__ . '/update-state.php';
require_once __DIR__ . '/update-transport.php';

final class PagecoreUpdateOutcome {
    public $ok;
    public $status;
    public $message;
    public $stage;
    public $from;
    public $to;
    public function __construct($ok, $status, $message, $stage = '', $from = null, $to = null) {
        $this->ok = (bool) $ok;
        $this->status = (string) $status;
        $this->message = (string) $message;
        $this->stage = (string) $stage;
        $this->from = $from;
        $this->to = $to;
    }
}

/**
 * Applies a published build over the installed `cms/` directory.
 *
 * Every stage before `commit` is reversible and leaves the live tree alone.
 * `commit` is two renames inside the site root, which is the only moment the
 * running installation changes. See docs/auto-update.md for the full contract.
 */
final class PagecoreUpdateInstaller {
    private $siteRoot;
    private $stateDirectory;
    private $workDirectory;
    private $options;
    private $transport;

    public function __construct(array $options) {
        $this->siteRoot = rtrim((string) $options['site_root'], '/\\');
        $this->stateDirectory = rtrim((string) $options['state_dir'], '/\\');
        $this->workDirectory = rtrim((string) $options['work_dir'], '/\\');
        $this->options = $options;
        $this->transport = isset($options['transport']) && $options['transport'] instanceof PagecoreUpdateTransport
            ? $options['transport']
            : null;
    }

    private function option($key, $default = null) {
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }

    public function cmsDirectory() {
        return $this->siteRoot . '/cms';
    }

    /**
     * Load every dependency used after the directory swap.
     *
     * The running script lives inside `cms/`, so once `commit` renames that
     * directory any further `require` would read the incoming tree — possibly
     * mid-swap. Nothing below `commit` may load new code.
     */
    public static function warm() {
        return class_exists('PagecoreUpdateArchive')
            && class_exists('PagecoreUpdateFiles')
            && class_exists('PagecoreUpdatePolicy')
            && class_exists('PagecoreUpdateState')
            && class_exists('PagecoreOperationalBoundary')
            && class_exists('PagecoreJsonPolicy')
            && class_exists('PagecoreUpdateOutcome');
    }

    /** Environment readiness, reported as rows the update page renders verbatim. */
    public function preflight($feed = null) {
        $checks = array();
        $checks[] = $this->check('apply_enabled', 'Updates may be applied', (bool) $this->option('apply', false),
            $this->option('apply', false) ? 'update_apply is enabled.' : 'Set update_apply to true to allow this instance to replace its own code.');
        $checks[] = $this->check('not_checkout', 'Not a source checkout', !$this->isCheckout(),
            $this->isCheckout() ? 'A .git directory is present; a working checkout never self-updates.' : 'No working checkout detected.');
        $writableRoot = PagecoreUpdateFiles::isWritableDirectory($this->siteRoot);
        $checks[] = $this->check('site_root_writable', 'Site root is writable', $writableRoot,
            $writableRoot ? $this->siteRoot : 'The PHP worker cannot create entries in the site root.');
        $writableCms = is_dir($this->cmsDirectory()) && is_writable($this->cmsDirectory());
        $checks[] = $this->check('cms_writable', 'Engine directory is writable', $writableCms,
            $writableCms ? $this->cmsDirectory() : 'The PHP worker cannot replace the cms directory.');
        $checks[] = $this->check('private_state', 'Private state directory is usable', PagecoreUpdateState::ensureDirectory($this->stateDirectory),
            $this->stateDirectory);
        $archiveSupport = PagecoreUpdateArchive::available();
        $checks[] = $this->check('archive_support', 'Archive support present', $archiveSupport,
            $archiveSupport ? 'ZipArchive or PharData is available.' : 'Neither ZipArchive nor PharData is installed.');
        $transportSupport = PagecoreUpdateTransport::available();
        $checks[] = $this->check('transport', 'HTTPS client available', $transportSupport,
            $transportSupport ? 'curl or allow_url_fopen is available.' : 'Install curl or enable allow_url_fopen.');

        if (is_array($feed)) {
            $required = ((int) $feed['archive_bytes']) * 3;
            $free = @disk_free_space($this->siteRoot);
            $enough = $free === false || $free >= $required;
            $checks[] = $this->check('disk', 'Free disk space', $enough,
                $free === false ? 'Not reported by this host.' : self::bytes((int) $free) . ' free, ' . self::bytes($required) . ' required.');
            $phpOk = version_compare(PHP_VERSION, $feed['min_php'], '>=');
            $checks[] = $this->check('php', 'PHP version', $phpOk, PHP_VERSION . ' (requires ' . $feed['min_php'] . ')');
        }
        return $checks;
    }

    public function preflightPassed(array $checks) {
        foreach ($checks as $check) {
            if (!$check['ok']) { return false; }
        }
        return true;
    }

    private function check($id, $label, $ok, $detail) {
        return array('id' => $id, 'label' => $label, 'ok' => (bool) $ok, 'detail' => (string) $detail);
    }

    public function isCheckout() {
        $directory = $this->siteRoot;
        for ($depth = 0; $depth < 4; $depth++) {
            if (is_dir($directory . '/.git')) { return true; }
            $parent = dirname($directory);
            if ($parent === $directory) { break; }
            $directory = $parent;
        }
        return false;
    }

    /** Retained pre-update snapshots, newest first. */
    public function snapshots() {
        $items = PagecoreOperationalBoundary::directoryItems($this->workDirectory, 'update.snapshots');
        if (!$items->ok) { return array(); }
        $found = array();
        foreach ((array) $items->value as $item) {
            if (strncmp($item, 'rollback-', 9) !== 0 || !is_dir($this->workDirectory . '/' . $item)) { continue; }
            $parts = explode('-', $item);
            $found[] = array(
                'name' => $item,
                'path' => $this->workDirectory . '/' . $item,
                'version' => isset($parts[1]) ? $parts[1] : '',
                'created' => (int) @filemtime($this->workDirectory . '/' . $item),
            );
        }
        usort($found, function ($left, $right) { return $right['created'] <=> $left['created']; });
        return $found;
    }

    /** Serialize every apply behind one non-blocking lock. */
    public function apply(array $feed, array $installed) {
        $lock = PagecoreUpdateState::lock($this->stateDirectory);
        if ($lock === null) {
            return new PagecoreUpdateOutcome(false, 'busy', 'Another update is already running.', 'lock');
        }
        try {
            return $this->run($feed, $installed);
        } catch (Throwable $error) {
            return new PagecoreUpdateOutcome(false, 'failed', 'The update stopped unexpectedly.', 'unhandled');
        } finally {
            PagecoreUpdateState::unlock($lock);
        }
    }

    private function run(array $feed, array $installed) {
        $from = PagecoreUpdatePolicy::describe($installed);
        $to = PagecoreUpdatePolicy::describe($feed);

        $checks = $this->preflight($feed);
        if (!$this->preflightPassed($checks)) {
            foreach ($checks as $check) {
                if (!$check['ok']) { return new PagecoreUpdateOutcome(false, 'blocked', $check['label'] . ': ' . $check['detail'], 'preflight', $from, $to); }
            }
        }
        if (!self::warm()) {
            return new PagecoreUpdateOutcome(false, 'failed', 'Update components could not be prepared.', 'preflight', $from, $to);
        }

        $identifier = bin2hex(random_bytes(8));
        $work = $this->workDirectory . '/work-' . $identifier;
        $staged = $this->siteRoot . '/cms.new-' . $identifier;
        $retired = $this->siteRoot . '/cms.old-' . $identifier;

        if (!is_dir($work) && !@mkdir($work, 0700, true) && !is_dir($work)) {
            return new PagecoreUpdateOutcome(false, 'failed', 'Could not create the update work directory.', 'preflight', $from, $to);
        }

        try {
            $archivePath = $work . '/archive' . $this->archiveExtension($feed['archive_url']);
            $download = $this->transport()->download($feed['archive_url'], $archivePath);
            if (!$download->ok) {
                return $this->abort($work, 'download', $download->error ? $download->error : 'The archive could not be downloaded.', $from, $to);
            }
            $digest = @hash_file('sha256', $archivePath);
            if (!is_string($digest) || !hash_equals($feed['archive_sha256'], $digest)) {
                return $this->abort($work, 'download', 'The downloaded archive failed its checksum.', $from, $to);
            }

            $tree = $work . '/tree';
            $extraction = PagecoreUpdateArchive::extract($archivePath, $tree);
            if (!$extraction->ok) { return $this->abort($work, 'extract', $extraction->error, $from, $to); }

            $manifest = PagecoreUpdateArchive::readManifest($tree);
            if ($manifest === null) { return $this->abort($work, 'verify', 'The archive manifest is missing or malformed.', $from, $to); }
            $verification = PagecoreUpdateArchive::verify($tree, $manifest, $feed);
            if (!$verification->ok) { return $this->abort($work, 'verify', $verification->error, $from, $to); }

            if (!$this->snapshot($installed)) {
                return $this->abort($work, 'snapshot', 'Could not store a rollback snapshot of the current engine.', $from, $to);
            }

            if (!$this->moveTree($tree . '/cms', $staged)) {
                PagecoreUpdateFiles::removeTree($staged);
                return $this->abort($work, 'stage', 'Could not stage the new engine beside the current one.', $from, $to);
            }
            if (!$this->preserve($staged)) {
                PagecoreUpdateFiles::removeTree($staged);
                return $this->abort($work, 'preserve', 'Could not carry the existing configuration into the new engine.', $from, $to);
            }

            // ---- Nothing below this line may require() a path inside cms/. ----
            PagecoreUpdateState::setMaintenance($this->stateDirectory, $identifier);
            $live = $this->cmsDirectory();
            if (!@rename($live, $retired)) {
                PagecoreUpdateState::clearMaintenance($this->stateDirectory);
                PagecoreUpdateFiles::removeTree($staged);
                return $this->abort($work, 'commit', 'Could not retire the current engine directory.', $from, $to);
            }
            if (!@rename($staged, $live)) {
                @rename($retired, $live);
                PagecoreUpdateState::clearMaintenance($this->stateDirectory);
                PagecoreUpdateFiles::removeTree($staged);
                return $this->abort($work, 'commit', 'Could not install the new engine directory; the previous one was restored.', $from, $to);
            }

            $failure = $this->postcheck($live, $manifest);
            if ($failure !== null) {
                @rename($live, $staged);
                @rename($retired, $live);
                PagecoreUpdateState::clearMaintenance($this->stateDirectory);
                PagecoreUpdateFiles::removeTree($staged);
                return $this->abort($work, 'postcheck', $failure . ' The previous version was restored.', $from, $to);
            }

            if (function_exists('opcache_reset')) { @opcache_reset(); }
            PagecoreUpdateState::clearMaintenance($this->stateDirectory);
            PagecoreUpdateFiles::removeTree($retired);
            PagecoreUpdateFiles::removeTree($work);
            $this->pruneSnapshots();
            return new PagecoreUpdateOutcome(true, 'applied', 'Updated to ' . $to . '.', 'finalize', $from, $to);
        } catch (Throwable $error) {
            PagecoreUpdateFiles::removeTree($work);
            PagecoreUpdateState::clearMaintenance($this->stateDirectory);
            return new PagecoreUpdateOutcome(false, 'failed', 'The update stopped unexpectedly.', 'unhandled', $from, $to);
        }
    }

    private function abort($work, $stage, $message, $from, $to) {
        PagecoreUpdateFiles::removeTree($work);
        return new PagecoreUpdateOutcome(false, 'failed', $message, $stage, $from, $to);
    }

    private function transport() {
        if ($this->transport === null) {
            $this->transport = new PagecoreUpdateTransport(array(
                'allowed_hosts' => (array) $this->option('allowed_hosts', array()),
                'timeout' => (int) $this->option('download_timeout', 120),
                'max_bytes' => (int) $this->option('max_archive_bytes', PagecoreUpdatePolicy::DEFAULT_MAX_ARCHIVE_BYTES),
                'user_agent' => (string) $this->option('user_agent', 'Pagecore'),
                'proxy' => (string) $this->option('proxy', ''),
                'ca_bundle' => (string) $this->option('ca_bundle', ''),
            ));
        }
        return $this->transport;
    }

    private function archiveExtension($url) {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return substr($path, -7) === '.tar.gz' ? '.tar.gz' : '.zip';
    }

    private function snapshot(array $installed) {
        if (!PagecoreUpdateState::ensureDirectory($this->workDirectory)) { return false; }
        $version = isset($installed['version']) ? $installed['version'] : '0.0.0';
        $target = $this->workDirectory . '/rollback-' . preg_replace('~[^0-9A-Za-z._-]~', '', $version) . '-' . gmdate('Ymd\THis');
        if (is_dir($target)) { PagecoreUpdateFiles::removeTree($target); }
        return PagecoreUpdateFiles::copyTree($this->cmsDirectory(), $target);
    }

    private function pruneSnapshots() {
        $keep = max(0, (int) $this->option('keep', 3));
        $snapshots = $this->snapshots();
        for ($index = $keep; $index < count($snapshots); $index++) {
            PagecoreUpdateFiles::removeTree($snapshots[$index]['path']);
        }
    }

    /** Rename where possible; copy across a filesystem boundary. */
    private function moveTree($source, $target) {
        if (@rename($source, $target)) { return true; }
        if (!PagecoreUpdateFiles::copyTree($source, $target)) { return false; }
        PagecoreUpdateFiles::removeTree($source);
        return is_dir($target);
    }

    /** Carry forward files the release must never overwrite, such as a flat-layout config. */
    private function preserve($staged) {
        foreach ((array) $this->option('preserve', array('config.php')) as $name) {
            $relative = PagecoreUpdateFiles::safeRelativePath($name);
            if ($relative === null) { return false; }
            $source = $this->cmsDirectory() . '/' . $relative;
            if (!is_file($source)) { continue; }
            $destination = $staged . '/' . $relative;
            $directory = dirname($destination);
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) { return false; }
            if (!@copy($source, $destination)) { return false; }
        }
        return true;
    }

    /** Structural proof that the swapped tree is complete and parseable. */
    private function postcheck($live, array $manifest) {
        foreach ($manifest['files'] as $relative => $expected) {
            if (strncmp($relative, 'cms/', 4) !== 0) { continue; }
            $path = $live . '/' . substr($relative, 4);
            if (!is_file($path)) { return 'The installed engine is missing ' . $relative . '.'; }
        }
        if (!PagecoreUpdateArchive::parses($live . '/engine.php')) {
            return 'The installed engine did not parse.';
        }
        return null;
    }

    private static function bytes($value) {
        $value = (int) $value;
        if ($value >= 1048576) { return round($value / 1048576, 1) . ' MB'; }
        if ($value >= 1024) { return round($value / 1024, 1) . ' KB'; }
        return $value . ' B';
    }
}
