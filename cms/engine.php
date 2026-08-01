<?php
/**
 * CMS engine — the reusable, database-free editing layer.
 *
 * Install into any PHP site:
 *   1. copy the cms/ directory next to your document-root files
 *   2. adjust cms/config.php
 *   3. require __DIR__ . '/cms/engine.php';  in your bootstrap
 *   4. replace editable fragments with  <?= cms_editable('page/region') ?>
 *   5. (posts) call cms_posts('category') in listings and cms_post($slug)
 *      in a post template; emit cms_assets() before </body>
 *
 * Content lives in content/pages/<page>/<region>.md and
 * content/posts/<slug>.md — the engine never modifies PHP templates.
 *
 * PHP 8.3+; the supported-version policy is enforced before bootstrapping.
 */

if (defined('CMS_LOADED')) { return; }
define('CMS_LOADED', 1);

define('CMS_DIR', __DIR__);
require_once __DIR__ . '/runtime.php';
define('PAGECORE_VERSION', '2.31.0');
$cmsConfigFile = defined('CMS_CONFIG_FILE') ? CMS_CONFIG_FILE : getenv('PAGECORE_CONFIG');
if (!$cmsConfigFile) { $cmsConfigFile = __DIR__ . '/config.php'; }
$cmsDevelopment = getenv('PAGECORE_DEVELOPMENT') === '1';
require_once __DIR__ . '/config-schema.php';
require_once __DIR__ . '/modules/PathPolicy.php';
require_once __DIR__ . '/modules/SessionContext.php';
require_once __DIR__ . '/modules/ContentPolicy.php';
require_once __DIR__ . '/modules/FrontMatter.php';
require_once __DIR__ . '/modules/Routes.php';
require_once __DIR__ . '/modules/MediaReferences.php';
list($cmsConfig, $cmsConfigErrors) = cms_validate_config(require $cmsConfigFile, !$cmsDevelopment);
if ($cmsConfigErrors) {
    error_log('Pagecore configuration invalid: ' . implode('; ', $cmsConfigErrors));
    throw new RuntimeException('Pagecore configuration is invalid. Check the server error log.');
}
$GLOBALS['CMS_CONFIG'] = $cmsConfig;
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/transport.php';

/** True when a path is the root itself or is nested below it. */
function cms_path_within_root($path, $root) {
    return PagecorePathPolicy::isWithin($path, $root);
}

/** List security-sensitive storage paths that remain inside the document root. */
function cms_private_storage_violations($documentRoot, $configFile) {
    $violations = array();
    if (!$documentRoot) { return $violations; }
    foreach (array(
        'configuration' => $configFile,
        'content_dir' => cms_cfg('content_dir'),
        'backup_dir' => cms_cfg('backup_dir'),
        'uploads_dir' => cms_cfg('uploads_dir'),
        'login_rate_limit_dir' => cms_cfg('login_rate_limit_dir', cms_cfg('content_dir') . '/.state'),
    ) as $label => $path) {
        if (!$path || cms_path_within_root($path, $documentRoot)) { $violations[] = $label; }
    }
    return $violations;
}

// Demo/local servers opt in explicitly. Production fails closed until secrets,
// source Markdown, drafts, backups, and uploads are outside the public root.
if (cms_cfg('demo_credentials', false) === true && cms_cfg('development_only', false) !== true) {
    cms_audit_event('config.demo_credentials', 'failure', array('reason' => 'production_profile'));
    throw new RuntimeException('Demo credentials are allowed only in an explicitly development-only Pagecore configuration.');
}
if (cms_cfg('development_only', false) === true) {
    $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $loopback = PHP_SAPI === 'cli' || $remote === '127.0.0.1' || $remote === '::1';
    if (!$cmsDevelopment || !$loopback) {
        cms_audit_event('config.development_only', 'failure', array('reason' => 'unsafe_runtime'));
        throw new RuntimeException('This Pagecore configuration is development-only and requires an explicit loopback development runtime.');
    }
}
if (PHP_SAPI !== 'cli' && !$cmsDevelopment) {
    $storageViolations = cms_private_storage_violations(
        isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '',
        $cmsConfigFile
    );
    if ($storageViolations) {
        cms_audit_event('config.private_storage', 'failure', array('reason' => 'document_root_overlap'));
        throw new RuntimeException('Pagecore private storage must be outside DOCUMENT_ROOT: ' . implode(', ', $storageViolations));
    }
}

$cmsTransport = cms_transport_policy($_SERVER, $GLOBALS['CMS_CONFIG'], $cmsDevelopment);
if (PHP_SAPI !== 'cli' && $cmsTransport['reject']) {
    http_response_code(400);
    header('Cache-Control: no-store');
    echo 'HTTPS is required.';
    exit;
}
if (PHP_SAPI !== 'cli' && $cmsTransport['hsts']) {
    $hsts = 'max-age=' . $cmsTransport['hsts_max_age'];
    if ($cmsTransport['hsts_include_subdomains']) { $hsts .= '; includeSubDomains'; }
    header('Strict-Transport-Security: ' . $hsts);
}

function cms_csp_nonce() {
    if (empty($GLOBALS['CMS_CSP_NONCE'])) {
        $GLOBALS['CMS_CSP_NONCE'] = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }
    return $GLOBALS['CMS_CSP_NONCE'];
}

function cms_send_security_headers() {
    if (PHP_SAPI === 'cli' || headers_sent()) { return; }
    $nonce = cms_csp_nonce();
    $policy = array(
        "default-src 'self'",
        "base-uri 'self'",
        "connect-src 'self'",
        "font-src 'self' https://fonts.gstatic.com data:",
        "form-action 'self'",
        "frame-ancestors 'none'",
        "frame-src 'none'",
        "img-src 'self' data: blob: https:",
        "object-src 'none'",
        "script-src 'self' 'nonce-$nonce'",
        "script-src-attr 'none'",
        "style-src 'self' https://fonts.googleapis.com 'nonce-$nonce'",
        "style-src-attr 'none'",
    );
    header('Content-Security-Policy: ' . implode('; ', $policy));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('X-Request-ID: ' . cms_correlation_id());
}

cms_send_security_headers();

/* ---------------------------------------------------------------- session */
PagecoreSessionContext::start($GLOBALS['CMS_CONFIG'], $cmsTransport);

/* ----------------------------------------------------------------- config */
function cms_cfg($key, $default = null) {
    $c = $GLOBALS['CMS_CONFIG'];
    return array_key_exists($key, $c) ? $c[$key] : $default;
}

function cms_version() {
    return PAGECORE_VERSION;
}

function cms_is_logged_in() {
    return !empty($_SESSION['cms_auth']);
}

function cms_csrf_token() {
    return isset($_SESSION['cms_csrf']) ? $_SESSION['cms_csrf'] : '';
}

/* ------------------------------------------------------------- key safety */
/**
 * Validate a region key ("page/region", up to 3 segments) and resolve it to
 * an absolute path inside content/pages. Returns null for anything unsafe.
 */
function cms_region_path($key, $mustExist = false) {
    if (!preg_match('~^[a-z0-9-]+(/[a-z0-9-]+){0,2}$~', $key)) { return null; }
    return PagecorePathPolicy::resolveWithin(cms_cfg('content_dir'), 'pages/' . $key . '.md', $mustExist);
}

/** Validate a post slug and resolve to content/posts/<slug>.md. */
function cms_post_path($slug, $mustExist = false) {
    if (!preg_match('~^[a-z0-9-]+$~', $slug)) { return null; }
    return PagecorePathPolicy::resolveWithin(cms_cfg('content_dir'), 'posts/' . $slug . '.md', $mustExist);
}

/* -------------------------------------------------------- atomic file I/O */
/** Return a stable optimistic-concurrency token for a file or a missing target. */
function cms_content_revision($path) {
    if (!is_file($path)) { return 'missing'; }
    $hash = hash_file('sha256', $path);
    return $hash === false ? null : $hash;
}

function cms_site_url($path = '') { return PagecoreRoutes::join(cms_cfg('base_url', '/'), $path); }
function cms_admin_url($path = '') { return PagecoreRoutes::join(cms_cfg('cms_url', '/cms'), $path); }

/** Run a mutation while holding an advisory lock scoped to one logical resource. */
function cms_with_resource_lock($resource, callable $callback) {
    $dir = cms_cfg('content_dir') . '/.locks';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the content lock directory.');
    }
    $path = $dir . '/' . hash('sha256', (string) $resource) . '.lock';
    $handle = fopen($path, 'c+b');
    if ($handle === false) { throw new RuntimeException('Could not open a content lock.'); }
    try {
        if (!flock($handle, LOCK_EX)) { throw new RuntimeException('Could not acquire a content lock.'); }
        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Write atomically while retaining a complete recoverable target on Windows failures. */
function cms_atomic_write($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true)) { return false; }
    }
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $handle = fopen($tmp, 'xb');
    if ($handle === false) { return false; }
    $written = fwrite($handle, $data);
    if ($written === false || $written !== strlen($data) || !fflush($handle)) {
        fclose($handle);
        @unlink($tmp);
        return false;
    }
    if (function_exists('fsync')) { @fsync($handle); }
    fclose($handle);
    if (!empty($GLOBALS['PAGECORE_WRITE_FAILURES']) && is_array($GLOBALS['PAGECORE_WRITE_FAILURES'])) {
        $match = array_search(basename($path), $GLOBALS['PAGECORE_WRITE_FAILURES'], true);
        if ($match !== false) {
            unset($GLOBALS['PAGECORE_WRITE_FAILURES'][$match]);
            @unlink($tmp);
            return false;
        }
    }
    $forceRecoveryPath = !empty($GLOBALS['PAGECORE_FORCE_RECOVERABLE_REPLACE']);
    if (!$forceRecoveryPath && rename($tmp, $path)) { return true; }

    // Windows cannot replace an existing target with rename(). Keep a complete
    // recovery copy until the new file is known to be in place.
    if (!is_file($path)) { @unlink($tmp); return false; }
    $recovery = $path . '.replace-' . bin2hex(random_bytes(4)) . '.bak';
    if (!copy($path, $recovery)) { @unlink($tmp); return false; }
    if (isset($GLOBALS['PAGECORE_ATOMIC_WRITE_FAILURE']) && $GLOBALS['PAGECORE_ATOMIC_WRITE_FAILURE'] === 'after-recovery') {
        @unlink($tmp);
        return false;
    }
    if (!unlink($path)) { @unlink($tmp); return false; }
    if (rename($tmp, $path)) {
        @unlink($recovery);
        return true;
    }
    // Best-effort restoration preserves the old complete file. If restoration
    // is blocked, the uniquely named recovery copy remains for an operator.
    if (!is_file($path)) { @rename($recovery, $path); }
    @unlink($tmp);
    return false;
}

/** Capture complete file values so a multi-file mutation can be rolled back. */
function cms_file_snapshot(array $paths) {
    $snapshot = array();
    foreach (array_unique($paths) as $path) {
        $exists = is_file($path);
        $data = $exists ? file_get_contents($path) : null;
        if ($exists && $data === false) { return null; }
        $snapshot[$path] = array('exists' => $exists, 'data' => $data);
    }
    return $snapshot;
}

/** Restore a snapshot; return false if any file cannot be restored completely. */
function cms_restore_file_snapshot(array $snapshot) {
    $ok = true;
    foreach ($snapshot as $path => $state) {
        if ($state['exists']) {
            if (!cms_atomic_write($path, $state['data'])) { $ok = false; }
        } elseif (is_file($path) && !@unlink($path)) {
            $ok = false;
        }
    }
    return $ok;
}

/** Back up the current file for a key ("pages/foo/bar" or "posts/slug"). */
function cms_backup($relKey, $path) {
    if (!is_file($path)) { return; }
    $bdir = cms_cfg('backup_dir') . '/' . dirname($relKey);
    if (!is_dir($bdir)) {
        if (!mkdir($bdir, 0775, true)) { return; }
    }
    $name = basename($relKey) . '.' . date('Ymd-His') . '.' . substr(bin2hex(random_bytes(2)), 0, 4) . '.md';
    if (!copy($path, $bdir . '/' . $name)) { return; }
    // prune to the newest N
    $keep = (int) cms_cfg('backup_keep', 20);
    $files = glob($bdir . '/' . basename($relKey) . '.*.md');
    if ($files && count($files) > $keep) {
        sort($files); // timestamped names sort chronologically
        foreach (array_slice($files, 0, count($files) - $keep) as $old) {
            @unlink($old);  // Suppress errors for concurrent access
        }
    }
}

function cms_target_rel_key($kind, $id) {
    return $kind === 'post' ? 'posts/' . $id : 'pages/' . $id;
}

function cms_draft_region_path($key, $mustExist = false) {
    if (!preg_match('~^[a-z0-9-]+(/[a-z0-9-]+){0,2}$~', $key)) { return null; }
    return PagecorePathPolicy::resolveWithin(cms_cfg('content_dir'), '.drafts/pages/' . $key . '.md', $mustExist);
}

function cms_draft_post_path($slug, $mustExist = false) {
    if (!preg_match('~^[a-z0-9-]+$~', $slug)) { return null; }
    return PagecorePathPolicy::resolveWithin(cms_cfg('content_dir'), '.drafts/posts/' . $slug . '.md', $mustExist);
}

function cms_draft_path($kind, $id, $mustExist = false) {
    return $kind === 'post'
        ? cms_draft_post_path($id, $mustExist)
        : cms_draft_region_path($id, $mustExist);
}

function cms_remove_empty_dirs($dir, $stop) {
    $dir = rtrim($dir, '/\\');
    $stop = rtrim($stop, '/\\');
    while ($dir !== '' && str_replace('\\', '/', $dir) !== str_replace('\\', '/', $stop) && is_dir($dir)) {
        $items = @scandir($dir);  // Suppress errors for concurrent access
        if ($items === false || count($items) > 2) { break; }
        if (!@rmdir($dir)) { break; }  // Suppress errors for concurrent access
        $dir = dirname($dir);
    }
}

function cms_clear_draft($kind, $id) {
    $path = cms_draft_path($kind, $id, true);
    if (!$path) { return; }
    @unlink($path);  // Suppress errors if file is locked or already deleted
    cms_remove_empty_dirs(dirname($path), cms_cfg('content_dir') . '/.drafts');
}

function cms_revision_id($file) {
    return PagecorePathPolicy::relativeTo($file, cms_cfg('backup_dir'));
}

function cms_revision_path($id) {
    if (!preg_match('~^[A-Za-z0-9._/-]+\.md$~', (string) $id)) { return null; }
    if (strpos($id, '..') !== false) { return null; }
    return PagecorePathPolicy::resolveWithin(cms_cfg('backup_dir'), $id, true);
}

function cms_revision_belongs_to($id, $relKey) {
    $prefix = dirname($relKey);
    if ($prefix === '.' || $prefix === '') {
        $prefix = '';
    } else {
        $prefix .= '/';
    }
    $prefix .= basename($relKey) . '.';
    return strpos($id, $prefix) === 0 && substr($id, -3) === '.md';
}

function cms_revision_label($file, $relKey) {
    $name = basename($file);
    $pattern = '~^' . preg_quote(basename($relKey), '~') . '\.(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\.[a-f0-9]+\.md$~';
    if (preg_match($pattern, $name, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5] . ':' . $m[6];
    }
    return date('Y-m-d H:i:s', filemtime($file));
}

function cms_revisions($relKey) {
    $dir = cms_cfg('backup_dir') . '/' . dirname($relKey);
    $files = glob($dir . '/' . basename($relKey) . '.*.md');
    if (!$files) { return array(); }
    rsort($files, SORT_STRING);
    $out = array();
    foreach ($files as $file) {
        $id = cms_revision_id($file);
        if ($id === null) { continue; }
        $out[] = array(
            'id'      => $id,
            'label'   => cms_revision_label($file, $relKey),
            'size'    => filesize($file),
            'modified'=> filemtime($file),
        );
    }
    return $out;
}

/* --------------------------------------------------------------- media */
function cms_media_exts() {
    $configured = array_map('strtolower', cms_cfg('allowed_ext', array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf')));
    // SVG is active XML and is never accepted as an editor-managed upload.
    return array_values(array_diff($configured, array('svg')));
}

function cms_media_is_valid_rel($rel) {
    $rel = str_replace('\\', '/', (string) $rel);
    if ($rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false) { return false; }
    if (!preg_match('~^[A-Za-z0-9._/-]+$~', $rel)) { return false; }
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    return in_array($ext, cms_media_exts(), true);
}

function cms_media_path($rel, $mustExist = true) {
    if (!cms_media_is_valid_rel($rel)) { return null; }
    return PagecorePathPolicy::resolveWithin(cms_cfg('uploads_dir'), $rel, $mustExist);
}

function cms_media_rel_from_path($path) {
    return PagecorePathPolicy::relativeTo($path, cms_cfg('uploads_dir'));
}

function cms_media_url($rel) {
    return cms_admin_url('media-file.php') . '?path=' . rawurlencode(str_replace('\\', '/', $rel));
}

/** Read a positive integer resource limit and fall back safely on invalid configuration. */
function cms_limit($key, $default) {
    $value = (int) cms_cfg($key, $default);
    return $value > 0 ? $value : (int) $default;
}

/** Measure a directory without following symlinks, stopping once either quota is exceeded. */
function cms_directory_usage($root, $byteLimit = PHP_INT_MAX, $fileLimit = PHP_INT_MAX) {
    $usage = array('bytes' => 0, 'files' => 0, 'exceeded' => false);
    if (!is_dir($root)) { return $usage; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if (!$file->isFile() || $file->isLink()) { continue; }
        $usage['files']++;
        $usage['bytes'] += max(0, (int) $file->getSize());
        if ($usage['bytes'] > $byteLimit || $usage['files'] > $fileLimit) {
            $usage['exceeded'] = true;
            break;
        }
    }
    return $usage;
}

function cms_media_kind($rel) {
    return strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'pdf' ? 'pdf' : 'image';
}

function cms_media_meta_path($path) {
    return $path . '.meta.json';
}

function cms_media_read_meta($path) {
    $metaPath = cms_media_meta_path($path);
    if (!is_file($metaPath)) { return array('alt' => '', 'caption' => ''); }
    $data = json_decode((string) file_get_contents($metaPath), true);
    if (!is_array($data)) { return array('alt' => '', 'caption' => ''); }
    return array(
        'alt' => isset($data['alt']) ? (string) $data['alt'] : '',
        'caption' => isset($data['caption']) ? (string) $data['caption'] : '',
    );
}

function cms_media_write_meta($path, array $meta) {
    $data = array(
        'alt' => str_replace(array("\r", "\n"), ' ', isset($meta['alt']) ? (string) $meta['alt'] : ''),
        'caption' => str_replace(array("\r", "\n"), ' ', isset($meta['caption']) ? (string) $meta['caption'] : ''),
    );
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return $json !== false && cms_atomic_write(cms_media_meta_path($path), $json . "\n");
}

function cms_media_markdown(array $asset) {
    $label = $asset['meta']['caption'] !== '' ? $asset['meta']['caption'] : $asset['filename_base'];
    if ($asset['kind'] === 'pdf') {
        return 'pdf:' . $asset['url'] . ' "' . str_replace('"', '', $label) . '"';
    }
    $alt = $asset['meta']['alt'] !== '' ? $asset['meta']['alt'] : $asset['filename_base'];
    $caption = $asset['meta']['caption'] !== '' ? ' "' . str_replace('"', '', $asset['meta']['caption']) . '"' : '';
    return '![' . str_replace(array('[', ']'), '', $alt) . '](' . $asset['url'] . $caption . ')';
}

function cms_media_asset($rel) {
    $path = cms_media_path($rel, true);
    if (!$path) { return null; }
    $rel = str_replace('\\', '/', $rel);
    $meta = cms_media_read_meta($path);
    $asset = array(
        'rel' => $rel,
        'url' => cms_media_url($rel),
        'kind' => cms_media_kind($rel),
        'filename' => basename($rel),
        'filename_base' => pathinfo($rel, PATHINFO_FILENAME),
        'size' => filesize($path),
        'modified' => filemtime($path),
        'meta' => $meta,
        'revision' => cms_content_revision($path),
        'meta_revision' => cms_content_revision(cms_media_meta_path($path)),
    );
    if ($asset['kind'] === 'image') {
        $size = @getimagesize($path);
        if ($size) {
            $asset['width'] = $size[0];
            $asset['height'] = $size[1];
        }
    }
    $asset['markdown'] = cms_media_markdown($asset);
    return $asset;
}

function cms_media_assets_page($query = '', $page = 1, $perPage = null) {
    $base = cms_cfg('uploads_dir');
    $perPage = max(1, min(cms_limit('max_media_page_size', 50), (int) ($perPage ?: cms_limit('media_page_size', 24))));
    $page = max(1, (int) $page);
    if (!is_dir($base)) {
        return array('items' => array(), 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'pages' => 1, 'truncated' => false);
    }
    $query = strtolower(trim((string) $query));
    $files = array();
    $scanLimit = cms_limit('max_inventory_items', 5000);
    $seen = 0;
    $truncated = false;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) { continue; }
        $rel = cms_media_rel_from_path($file->getPathname());
        if ($rel === null || !cms_media_is_valid_rel($rel)) { continue; }
        $seen++;
        if ($seen > $scanLimit) { $truncated = true; break; }
        $asset = cms_media_asset($rel);
        if (!$asset) { continue; }
        $haystack = strtolower($asset['rel'] . ' ' . $asset['meta']['alt'] . ' ' . $asset['meta']['caption']);
        if ($query !== '' && strpos($haystack, $query) === false) { continue; }
        $files[] = $asset;
    }
    usort($files, function ($a, $b) {
        $c = $b['modified'] - $a['modified'];
        return $c !== 0 ? $c : strcmp($a['rel'], $b['rel']);
    });
    $total = count($files);
    $pages = max(1, (int) ceil($total / $perPage));
    if ($page > $pages) { $page = $pages; }
    return array(
        'items' => array_slice($files, ($page - 1) * $perPage, $perPage),
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'pages' => $pages,
        'truncated' => $truncated,
    );
}

/** Compatibility facade for callers that only need the first bounded media page. */
function cms_media_assets($query = '') {
    $result = cms_media_assets_page($query, 1);
    return $result['items'];
}

function cms_media_reference_impacts($url, $rel = '') {
    $needles = array($url);
    if ($rel !== '' && cms_cfg('uploads_url', '') !== '') {
        $needles[] = rtrim(cms_cfg('uploads_url'), '/') . '/' . str_replace('%2F', '/', rawurlencode(str_replace('\\', '/', $rel)));
    }
    $impacts = array();
    foreach (cms_cfg('static_media_references', array()) as $reference) {
        if (in_array($reference, $needles, true)) { $impacts[] = array('type' => 'static', 'source' => 'configuration'); }
    }
    $roots = array(
        cms_cfg('content_dir') . '/pages',
        cms_cfg('content_dir') . '/posts',
        cms_cfg('content_dir') . '/.drafts',
    );
    foreach ($roots as $root) {
        if (!is_dir($root)) { continue; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') { continue; }
            $markdown = (string) file_get_contents($file->getPathname());
            if (PagecoreMediaReferences::matches($markdown, $needles)) {
                $relative = PagecorePathPolicy::relativeTo($file->getPathname(), cms_cfg('content_dir'));
                $impacts[] = array('type' => 'content', 'source' => $relative !== null ? $relative : $file->getFilename());
            }
        }
    }
    return $impacts;
}

function cms_media_is_referenced($url, $rel = '') {
    return cms_media_reference_impacts($url, $rel) !== array();
}

/* ------------------------------------------------------------ front matter */
/** Parse "---\nkey: value\n---\nbody" into array(meta, body). */
function cms_parse_front_matter_detailed($raw) {
    return PagecoreFrontMatter::parse($raw, array_keys(cms_cfg('categories', array())));
}

function cms_parse_front_matter($raw) {
    $parsed = cms_parse_front_matter_detailed($raw);
    if ($parsed['diagnostics']) {
        error_log('Pagecore front matter: ' . implode(' ', $parsed['diagnostics']));
    }
    return array($parsed['meta'], $parsed['body']);
}

function cms_build_front_matter(array $meta, $body) {
    return PagecoreFrontMatter::build($meta, $body);
}

/* -------------------------------------------------------------- rendering */
function cms_parsedown() {
    static $pd = null;
    if ($pd === null) {
        require_once CMS_DIR . '/lib/Parsedown.php';
        $pd = new Parsedown();
        $pd->setBreaksEnabled(false);
        // Editor-authored and imported Markdown is always untrusted. Raw HTML
        // cannot bypass Parsedown's URL and attribute sanitization through a
        // site-level configuration switch.
        $pd->setSafeMode(true);
        $pd->setUrlsLinked(false);
    }
    return $pd;
}

/**
 * Markdown -> HTML: Parsedown, then post-processing:
 *  - "pdf:/uploads/x.pdf \"Label\"" paragraphs -> download links
 *  - markdown-born <img> wrapped in <figure class="wp-block-image">
 *  - tables get class="cms-table"
 */
function cms_render_markdown($md) {
    $html = cms_parsedown()->text((string) $md);

    // pdf: directive — Parsedown renders it as a lone paragraph
    $html = preg_replace_callback(
        '~<p>\s*pdf:\s*(/[^\s"<]+\.pdf)(?:\s+&quot;([^&]*)&quot;|\s+"([^"<]*)")?\s*</p>~i',
        function ($m) {
            $url = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            $label = '';
            if (isset($m[3]) && $m[3] !== '') { $label = $m[3]; }
            elseif (isset($m[2]) && $m[2] !== '') { $label = $m[2]; }
            if ($label === '') { $label = basename($m[1], '.pdf'); }
            $lab = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            return '<p class="pdf-download"><a href="' . $url . '" download>Download PDF: ' . $lab . '</a></p>';
        },
        $html
    );

    // wrap paragraph-level markdown images in a <figure>
    $html = preg_replace(
        '~<p>(<img [^>]*>)</p>~',
        '<figure class="wp-block-image">$1</figure>',
        $html
    );

    // style hook for markdown tables
    $html = str_replace('<table>', '<table class="cms-table">', $html);

    return $html;
}

/* --------------------------------------------------------------- editable */
/**
 * Render an editable region. Anonymous visitors get the bare rendered
 * markdown; a logged-in editor gets it wrapped in a targetable element.
 */
function cms_editable($key, $tag = 'div') {
    $path = cms_region_path($key);
    $md = ($path && is_file($path)) ? file_get_contents($path) : '';
    $html = $md !== '' ? cms_render_markdown($md) : '';
    if (!cms_is_logged_in()) { return $html; }
    if ($html === '') {
        $html = '<p class="cms-empty">(empty content — click to edit)</p>';
    }
    return '<' . $tag . ' class="cms-editable" data-cms-key="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">'
         . $html . '</' . $tag . '>';
}

/* ------------------------------------------------------------------ posts */
/** English month names for the site's "j F Y" date format. */
function cms_date_display($iso) {
    static $months = array('', 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December');
    if (!preg_match('~^(\d{4})-(\d{2})-(\d{2})~', (string) $iso, $m)) { return (string) $iso; }
    return ((int) $m[3]) . ' ' . $months[(int) $m[2]] . ' ' . $m[1];
}

/** Estimated reading time in whole minutes (~250 words/min, min 1). */
function cms_reading_minutes($md) {
    $n = preg_match_all('~\S+~u', (string) $md, $ignore);
    return (int) max(1, (int) ceil($n / 250));
}

function cms_excerpt_from($md, $words = 28) {
    $html = preg_replace('~<[^>]+>~', ' ', cms_render_markdown($md));
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('~\s+~u', ' ', $html));
    $parts = preg_split('~ ~u', $text);
    if (count($parts) <= $words) { return $text; }
    return implode(' ', array_slice($parts, 0, $words)) . '…';
}

/** Absolute path of the server-side posts index cache (never HTTP-served). */
function cms_posts_index_path() {
    return cms_cfg('content_dir') . '/posts-index.json';
}

/**
 * Parse a front-matter `tags` value ("A, B, C") into a de-duplicated list of
 * array('slug' => ..., 'label' => ...). Labels are kept as authored; slugs are
 * derived with the same Polish-aware transliteration used for post slugs.
 */
function cms_parse_tags($value) {
    $out = array();
    $seen = array();
    foreach (preg_split('~\s*,\s*~u', (string) $value) as $label) {
        $label = trim($label);
        if ($label === '') { continue; }
        $slug = cms_tag_slugify($label);
        if ($slug === '' || isset($seen[$slug])) { continue; }
        $seen[$slug] = true;
        $out[] = array('slug' => $slug, 'label' => $label);
    }
    return $out;
}

/** Slugify a tag label (Polish transliteration; no uniqueness/file check). */
function cms_tag_slugify($label) {
    $map = array(
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
        'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z',
    );
    $s = strtolower(strtr((string) $label, $map));
    $s = preg_replace('~[^a-z0-9]+~', '-', $s);
    return trim($s, '-');
}

/**
 * Public URL for a post slug.
 *
 * A valid post_url contains {slug}. If a migrated config accidentally loses
 * that placeholder (for example, an unquoted PowerShell argument), append it
 * defensively so every listing link remains unique and usable.
 */
function cms_post_url($slug) {
    return PagecoreRoutes::post(cms_cfg('post_url', '/post/{slug}/'), $slug);
}

/** Convert a site-relative public URL to the absolute URL social crawlers need. */
function cms_absolute_url($url) {
    $url = trim((string) $url);
    if ($url === '' || preg_match('~^https?://~i', $url)) { return $url; }

    $site = rtrim((string) cms_cfg('site_url', ''), '/');
    if ($site === '') { return $url; }

    if (strpos($url, '//') === 0) {
        $scheme = parse_url($site, PHP_URL_SCHEME);
        return ($scheme ? $scheme : 'https') . ':' . $url;
    }

    if (isset($url[0]) && $url[0] === '/') {
        $scheme = parse_url($site, PHP_URL_SCHEME);
        $host = parse_url($site, PHP_URL_HOST);
        $port = parse_url($site, PHP_URL_PORT);
        if ($scheme && $host) {
            return $scheme . '://' . $host . ($port ? ':' . $port : '') . $url;
        }
    }

    return $site . '/' . ltrim($url, '/');
}

/**
 * Build the full post list by scanning every Markdown file on disk.
 * This is the source of truth; it is expensive (one read per post) and is
 * only used when (re)building the cached index, never on a normal page load.
 */
function cms_posts_from_disk() {
    $list = array();
    $cats = cms_cfg('categories');
    foreach (glob(cms_cfg('content_dir') . '/posts/*.md') as $file) {
        $slug = basename($file, '.md');
        $parsed = cms_parse_front_matter_detailed(file_get_contents($file));
        if ($parsed['diagnostics']) {
            error_log('Pagecore skipped malformed post metadata for ' . basename($file));
            continue;
        }
        $meta = $parsed['meta'];
        $body = $parsed['body'];
        if (!cms_post_status_is_public(isset($meta['status']) ? $meta['status'] : 'publish')) { continue; }
        $cat = isset($meta['category']) ? $meta['category'] : '';
        $list[] = array(
            'slug'           => $slug,
            'title'          => isset($meta['title']) ? $meta['title'] : $slug,
            'date'           => isset($meta['date']) ? $meta['date'] : '1970-01-01',
            'date_display'   => cms_date_display(isset($meta['date']) ? $meta['date'] : ''),
            'category'       => $cat,
            'category_label' => isset($cats[$cat]) ? $cats[$cat][0] : $cat,
            'excerpt'        => isset($meta['excerpt']) && $meta['excerpt'] !== ''
                                ? $meta['excerpt'] : cms_excerpt_from($body),
            'image'          => isset($meta['image']) ? $meta['image'] : '',
            'mins'           => cms_reading_minutes($body),
            'tags'           => cms_parse_tags(isset($meta['tags']) ? $meta['tags'] : ''),
            'url'            => cms_post_url($slug),
            'status'         => 'publish',
        );
    }
    usort($list, function ($a, $b) {
        $c = strcmp($b['date'], $a['date']);
        return $c !== 0 ? $c : strcmp($a['slug'], $b['slug']);
    });
    return $list;
}

/** Write the cached posts index; returns the list, or false on a write/encoding failure. */
function cms_write_posts_index($list = null) {
    if ($list === null) { $list = cms_posts_from_disk(); }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) { $flags |= JSON_INVALID_UTF8_SUBSTITUTE; }
    $json = json_encode($list, $flags);
    if ($json !== false && cms_atomic_write(cms_posts_index_path(), $json)) {
        return $list;
    } else {
        $reason = $json === false ? 'json_encode' : 'write_failed';
        cms_audit_event('index.posts', 'failure', array('reason' => $reason));
        error_log('CMS: posts index ' . $reason . ' — index left unchanged');
    }
    return false;
}

/**
 * Is the cached index fresh? It is stale when missing, or when the posts
 * directory has changed (a file added or removed) more recently than the
 * index was written. In-place edits through the editor rebuild the index
 * explicitly via cms_regenerate_indexes(), so this cheap one-stat check is
 * enough for the normal workflow. (Direct FTP edits of an existing file can
 * be picked up with a manual rebuild — see scripts/reindex.php.)
 */
function cms_posts_index_fresh() {
    $index = cms_posts_index_path();
    if (!is_file($index)) { return false; }
    $postsDir = cms_cfg('content_dir') . '/posts';
    if (is_dir($postsDir) && @filemtime($postsDir) > @filemtime($index)) { return false; }
    return true;
}

/**
 * All posts (newest first), optionally filtered by category slug.
 *
 * Reads the cached posts-index.json (one file) on a normal request. When the
 * cache is missing or stale it self-heals by scanning disk once and rewriting
 * the index, so the very first hit after an import pays the cost and every
 * later hit is a single JSON decode.
 */
function cms_posts($category = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = false;
        if (cms_posts_index_fresh()) {
            $raw = file_get_contents(cms_posts_index_path());
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $valid = true;
                foreach ($decoded as $cachedPost) {
                    if (!is_array($cachedPost) || !isset($cachedPost['status']) || $cachedPost['status'] !== 'publish') {
                        $valid = false;
                        break;
                    }
                }
                if ($valid) { $cache = $decoded; }
            }
        }
        if ($cache === false) {
            $diskPosts = cms_posts_from_disk();
            $writtenPosts = cms_write_posts_index($diskPosts);
            $cache = is_array($writtenPosts) ? $writtenPosts : $diskPosts;
        }
        // URLs are derived data. Recompute them from each slug so a stale index
        // cannot retain a broken post_url pattern after configuration is fixed.
        foreach ($cache as &$cachedPost) {
            if (isset($cachedPost['slug'])) { $cachedPost['url'] = cms_post_url($cachedPost['slug']); }
        }
        unset($cachedPost);
    }
    if ($category === null) { return $cache; }
    $out = array();
    foreach ($cache as $p) { if ($p['category'] === $category) { $out[] = $p; } }
    return $out;
}

/**
 * One page of posts for a listing view. Returns a slice plus paging metadata:
 *   items, total, page, per_page, pages, has_prev, has_next.
 * Page numbers are 1-based and clamped into range.
 */
function cms_paginate(array $all, $page = 1, $per_page = 10) {
    return PagecoreContentPolicy::page($all, $page, $per_page);
}

function cms_posts_page($category = null, $page = 1, $per_page = 10) {
    return cms_paginate(cms_posts($category), $page, $per_page);
}

/* -------------------------------------------------------------------- tags */
/** Posts (newest first) carrying a given tag slug. */
function cms_posts_by_tag($tagSlug) {
    $out = array();
    foreach (cms_posts() as $p) {
        if (empty($p['tags'])) { continue; }
        foreach ($p['tags'] as $t) {
            if ($t['slug'] === $tagSlug) { $out[] = $p; break; }
        }
    }
    return $out;
}

/** One page of posts for a tag listing. */
function cms_posts_page_by_tag($tagSlug, $page = 1, $per_page = 10) {
    return cms_paginate(cms_posts_by_tag($tagSlug), $page, $per_page);
}

/**
 * Tag registry derived from all posts: slug => array('label'=>, 'count'=>).
 * Labels use the most common spelling seen across posts. Cheap — built from the
 * in-memory index, cached per request.
 */
function cms_tags() {
    static $tags = null;
    if ($tags !== null) { return $tags; }
    $labels = array(); // slug => [label => hits]
    $count = array();  // slug => count
    foreach (cms_posts() as $p) {
        if (empty($p['tags'])) { continue; }
        foreach ($p['tags'] as $t) {
            $s = $t['slug'];
            $count[$s] = isset($count[$s]) ? $count[$s] + 1 : 1;
            $labels[$s][$t['label']] = isset($labels[$s][$t['label']]) ? $labels[$s][$t['label']] + 1 : 1;
        }
    }
    $tags = array();
    foreach ($count as $slug => $n) {
        arsort($labels[$slug]);
        $label = key($labels[$slug]);
        $tags[$slug] = array('label' => $label, 'count' => $n);
    }
    // stable: highest count first, then label
    uasort($tags, function ($a, $b) {
        if ($a['count'] !== $b['count']) { return $b['count'] - $a['count']; }
        return strcasecmp($a['label'], $b['label']);
    });
    return $tags;
}

/** Display label for a tag slug (falls back to the slug). */
function cms_tag_label($slug) {
    $tags = cms_tags();
    return isset($tags[$slug]) ? $tags[$slug]['label'] : $slug;
}

/** Only the canonical WordPress/Pagecore `publish` state is publicly visible. */
function cms_post_status_is_public($status) {
    return PagecoreContentPolicy::isPublicStatus($status);
}

/** One post with rendered body; null when unknown or not publicly visible. */
function cms_post($slug, $includeNonPublic = false) {
    $path = cms_post_path($slug, true);
    if (!$path) { return null; }
    $parsed = cms_parse_front_matter_detailed(file_get_contents($path));
    if ($parsed['diagnostics']) { error_log('Pagecore rejected malformed post metadata for ' . basename($path)); return null; }
    $meta = $parsed['meta'];
    $body = $parsed['body'];
    $status = isset($meta['status']) ? strtolower(trim($meta['status'])) : 'publish';
    if (!$includeNonPublic && !cms_post_status_is_public($status)) { return null; }
    $cats = cms_cfg('categories');
    $cat = isset($meta['category']) ? $meta['category'] : '';
    $excerpt = isset($meta['excerpt']) && trim($meta['excerpt']) !== ''
             ? trim($meta['excerpt']) : cms_excerpt_from($body);
    return array(
        'slug'           => $slug,
        'title'          => isset($meta['title']) ? $meta['title'] : $slug,
        'date'           => isset($meta['date']) ? $meta['date'] : '',
        'date_display'   => cms_date_display(isset($meta['date']) ? $meta['date'] : ''),
        'category'       => $cat,
        'category_label' => isset($cats[$cat]) ? $cats[$cat][0] : $cat,
        'lead'           => isset($meta['excerpt']) ? $meta['excerpt'] : '',
        'excerpt'        => $excerpt,
        'image'          => isset($meta['image']) ? $meta['image'] : '',
        'mins'           => cms_reading_minutes($body),
        'tags'           => cms_parse_tags(isset($meta['tags']) ? $meta['tags'] : ''),
        'body_md'        => $body,
        'body_html'      => cms_render_markdown($body),
        'url'            => cms_post_url($slug),
        'status'         => $status,
    );
}

/**
 * Metadata for rich post previews on Facebook and other Open Graph consumers.
 * Call this inside <head> after loading a post with cms_post().
 */
function cms_post_social_meta(array $post) {
    $escape = function ($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };
    $title = isset($post['title']) ? trim($post['title']) : '';
    $description = isset($post['excerpt']) ? $post['excerpt']
                 : (isset($post['lead']) ? $post['lead'] : '');
    $description = html_entity_decode(strip_tags((string) $description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $description = trim(preg_replace('~\s+~u', ' ', $description));
    $url = cms_absolute_url(isset($post['url']) ? $post['url'] : '');
    $image = cms_absolute_url(isset($post['image']) ? $post['image'] : '');
    $siteName = trim((string) cms_cfg('site_name', ''));

    $tags = array(
        '<meta name="description" content="' . $escape($description) . '">',
        '<meta property="og:type" content="article">',
        '<meta property="og:title" content="' . $escape($title) . '">',
        '<meta property="og:description" content="' . $escape($description) . '">',
        '<meta property="og:url" content="' . $escape($url) . '">',
        '<link rel="canonical" href="' . $escape($url) . '">',
    );
    if ($siteName !== '') {
        $tags[] = '<meta property="og:site_name" content="' . $escape($siteName) . '">';
    }
    if ($image !== '') {
        $tags[] = '<meta property="og:image" content="' . $escape($image) . '">';
        $tags[] = '<meta property="og:image:alt" content="' . $escape($title) . '">';
    }
    if (!empty($post['date'])) {
        $tags[] = '<meta property="article:published_time" content="' . $escape($post['date']) . '">';
    }
    if (!empty($post['category_label'])) {
        $tags[] = '<meta property="article:section" content="' . $escape($post['category_label']) . '">';
    }
    if (!empty($post['tags']) && is_array($post['tags'])) {
        foreach ($post['tags'] as $tag) {
            if (!empty($tag['label'])) {
                $tags[] = '<meta property="article:tag" content="' . $escape($tag['label']) . '">';
            }
        }
    }
    return implode("\n", $tags) . "\n";
}

/* -------------------------------------------------------- content / nav */
function cms_nav_file() {
    return cms_cfg('nav_file', cms_cfg('content_dir') . '/nav.json');
}

function cms_default_nav_items() {
    $items = array();
    foreach (cms_cfg('search_pages', array()) as $url => $def) {
        $items[] = array(
            'label' => isset($def[0]) ? (string) $def[0] : $url,
            'url' => $url,
            'children' => array(),
        );
    }
    return $items;
}

function cms_normalize_nav_item($item) {
    if (!is_array($item)) { return null; }
    $label = trim(isset($item['label']) ? (string) $item['label'] : '');
    $url = trim(isset($item['url']) ? (string) $item['url'] : '');
    if ($label === '' || $url === '') { return null; }
    if (!preg_match('~^(https?://|/)~i', $url)) { return null; }
    $children = array();
    if (isset($item['children']) && is_array($item['children'])) {
        foreach ($item['children'] as $child) {
            $normalized = cms_normalize_nav_item($child);
            if ($normalized) { $children[] = $normalized; }
        }
    }
    return array('label' => $label, 'url' => $url, 'children' => $children);
}

function cms_normalize_nav_items($items) {
    if (!is_array($items)) { return null; }
    $out = array();
    foreach ($items as $item) {
        $normalized = cms_normalize_nav_item($item);
        if ($normalized) { $out[] = $normalized; }
    }
    return $out;
}

function cms_nav_items() {
    $file = cms_nav_file();
    if (is_file($file)) {
        $data = json_decode((string) file_get_contents($file), true);
        $items = cms_normalize_nav_items($data);
        if ($items !== null) { return $items; }
    }
    return cms_default_nav_items();
}

function cms_nav_json() {
    return json_encode(cms_nav_items(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function cms_write_nav_json($raw, &$error = null) {
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $error = 'Navigation must be a JSON array.';
        return false;
    }
    $items = cms_normalize_nav_items($data);
    if ($items === null) {
        $error = 'Navigation JSON is not valid.';
        return false;
    }
    $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        $error = 'Navigation JSON could not be encoded.';
        return false;
    }
    if (!cms_atomic_write(cms_nav_file(), $json . "\n")) {
        $error = 'Navigation file could not be written.';
        return false;
    }
    return true;
}

function cms_nav_html_items(array $items) {
    $html = '';
    foreach ($items as $item) {
        $html .= '<li><a href="' . htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</a>';
        if (!empty($item['children'])) {
            $html .= '<ul>' . cms_nav_html_items($item['children']) . '</ul>';
        }
        $html .= '</li>';
    }
    return $html;
}

function cms_nav_html() {
    return '<ul class="cms-nav-list">' . cms_nav_html_items(cms_nav_items()) . '</ul>';
}

function cms_content_rel_path($path, $base) {
    return PagecorePathPolicy::relativeTo($path, $base);
}

function cms_region_files() {
    $base = cms_cfg('content_dir') . '/pages';
    if (!is_dir($base)) { return array(); }
    $out = array();
    $limit = cms_limit('max_inventory_items', 5000);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') { continue; }
        $rel = cms_content_rel_path($file->getPathname(), $base);
        if ($rel === null) { continue; }
        $key = preg_replace('~\.md$~i', '', str_replace('\\', '/', $rel));
        if (cms_region_path($key, true)) {
            $out[] = $key;
            if (count($out) >= $limit) { break; }
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

function cms_template_region_keys() {
    $root = cms_cfg('site_root');
    if (!is_dir($root)) { return array(); }
    $skip = array(
        '.git' => true,
        'cms' => true,
        'content' => true,
        'uploads' => true,
        'working-content' => true,
        'working-uploads' => true,
        'fixtures' => true,
        'node_modules' => true,
        'vendor' => true,
        'playwright-report' => true,
        'test-results' => true,
    );
    $keys = array();
    $limit = cms_limit('max_inventory_items', 5000);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') { continue; }
        $rel = cms_content_rel_path($file->getPathname(), $root);
        if ($rel === null) { continue; }
        $parts = explode('/', str_replace('\\', '/', $rel));
        if (isset($skip[$parts[0]])) { continue; }
        $src = (string) file_get_contents($file->getPathname());
        if (preg_match_all('~cms_editable\(\s*[\'"]([a-z0-9-]+(?:/[a-z0-9-]+){0,2})[\'"]~', $src, $m)) {
            foreach ($m[1] as $key) { $keys[$key] = true; }
        }
        if (preg_match_all('~data-cms-key\s*=\s*[\'"]([a-z0-9-]+(?:/[a-z0-9-]+){0,2})[\'"]~', $src, $m)) {
            foreach ($m[1] as $key) { $keys[$key] = true; }
        }
        if (count($keys) >= $limit) { break; }
    }
    $out = array_keys($keys);
    sort($out, SORT_STRING);
    return $out;
}

function cms_content_file_summary($path) {
    if (!is_file($path)) { return array('exists' => false, 'size' => 0, 'modified' => null); }
    return array('exists' => true, 'size' => filesize($path), 'modified' => filemtime($path));
}

/** Fold inventory search text without making the optional mbstring extension mandatory. */
function cms_content_search_fold($value) {
    $value = (string) $value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

/**
 * Build the content inventory with a small server-rendered slice of the posts index.
 * Keeping the page size capped prevents the browser from laying out every post at once.
 */
function cms_content_inventory($postQuery = '', $postCategory = '', $postPage = 1, $postsPerPage = 100) {
    $inventoryLimit = cms_limit('max_inventory_items', 5000);
    $searchPages = array_slice(cms_cfg('search_pages', array()), 0, $inventoryLimit, true);
    $pages = array();
    $regionSources = array();
    $regionUrls = array();

    foreach ($searchPages as $url => $def) {
        $region = isset($def[2]) ? (string) $def[2] : '';
        if ($region !== '') {
            if (!isset($regionSources[$region])) { $regionSources[$region] = array(); }
            $regionSources[$region]['search_pages'] = true;
            $regionUrls[$region] = $url;
        }
        $summary = $region !== '' ? cms_content_file_summary(cms_region_path($region, false)) : array('exists' => null, 'size' => 0, 'modified' => null);
        $pages[] = array(
            'title' => isset($def[0]) ? (string) $def[0] : $url,
            'type' => isset($def[1]) ? (string) $def[1] : 'Page',
            'url' => $url,
            'region' => $region,
            'exists' => $summary['exists'],
        );
    }

    foreach (cms_region_files() as $key) {
        if (!isset($regionSources[$key])) { $regionSources[$key] = array(); }
        $regionSources[$key]['file'] = true;
    }
    foreach (cms_template_region_keys() as $key) {
        if (!isset($regionSources[$key])) { $regionSources[$key] = array(); }
        $regionSources[$key]['template'] = true;
    }

    ksort($regionSources, SORT_STRING);
    $regions = array();
    $missing = array();
    foreach ($regionSources as $key => $sources) {
        $path = cms_region_path($key, false);
        $summary = $path ? cms_content_file_summary($path) : array('exists' => false, 'size' => 0, 'modified' => null);
        $draft = cms_draft_region_path($key, true);
        $item = array(
            'key' => $key,
            'path' => $path,
            'url' => isset($regionUrls[$key]) ? $regionUrls[$key] : '',
            'sources' => array_keys($sources),
            'exists' => $summary['exists'],
            'size' => $summary['size'],
            'modified' => $summary['modified'],
            'draft' => $draft ? true : false,
            'revision' => cms_content_revision($path),
        );
        $regions[] = $item;
        if (!$item['exists']) { $missing[] = $item; }
    }

    $allPosts = array_slice(cms_posts(), 0, $inventoryLimit);
    $postQuery = trim((string) $postQuery);
    $postCategory = trim((string) $postCategory);
    $postsPerPage = max(1, min(cms_limit('max_inventory_page_size', 100), (int) $postsPerPage));
    $postPage = max(1, (int) $postPage);
    $queryNeedle = cms_content_search_fold($postQuery);
    $filteredPosts = array();
    foreach ($allPosts as $post) {
        if ($postCategory !== '' && $post['category'] !== $postCategory) { continue; }
        // Search title and slug together so editors can find either the human or file-system identifier.
        if ($queryNeedle !== '' && strpos(cms_content_search_fold($post['title'] . ' ' . $post['slug']), $queryNeedle) === false) {
            continue;
        }
        $filteredPosts[] = $post;
    }
    $filteredPostCount = count($filteredPosts);
    $postPages = max(1, (int) ceil($filteredPostCount / $postsPerPage));
    if ($postPage > $postPages) { $postPage = $postPages; }
    $postOffset = ($postPage - 1) * $postsPerPage;
    $posts = array_slice($filteredPosts, $postOffset, $postsPerPage);
    foreach ($posts as &$post) {
        $post['revision'] = cms_content_revision(cms_post_path($post['slug'], false));
    }
    unset($post);

    $counts = array();
    foreach (cms_cfg('categories', array()) as $slug => $def) { $counts[$slug] = 0; }
    foreach ($allPosts as $post) {
        if (!isset($counts[$post['category']])) { $counts[$post['category']] = 0; }
        $counts[$post['category']]++;
    }
    $categories = array();
    foreach (cms_cfg('categories', array()) as $slug => $def) {
        $categories[] = array(
            'slug' => $slug,
            'label' => isset($def[0]) ? (string) $def[0] : $slug,
            'url' => isset($def[1]) ? (string) $def[1] : '',
            'posts' => isset($counts[$slug]) ? $counts[$slug] : 0,
        );
    }

    return array(
        'pages' => $pages,
        'regions' => $regions,
        'missing' => $missing,
        'posts' => $posts,
        'posts_total' => count($allPosts),
        'post_pagination' => array(
            'query' => $postQuery,
            'category' => $postCategory,
            'page' => $postPage,
            'per_page' => $postsPerPage,
            'total' => $filteredPostCount,
            'pages' => $postPages,
            'offset' => $postOffset,
            'has_prev' => $postPage > 1,
            'has_next' => $postPage < $postPages,
        ),
        'categories' => $categories,
        'nav' => array(
            'file' => cms_nav_file(),
            'exists' => is_file(cms_nav_file()),
            'items' => cms_nav_items(),
            'json' => cms_nav_json(),
            'revision' => cms_content_revision(cms_nav_file()),
        ),
    );
}

/** Convert a title to the stable Polish-aware base used for post slugs. */
function cms_post_slug_base($title) {
    $map = array(
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
        'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z',
    );
    $s = strtr((string) $title, $map);
    $s = strtolower($s);
    $s = preg_replace('~[^a-z0-9]+~', '-', $s);
    $s = trim($s, '-');
    return $s === '' ? 'post' : $s;
}

/** Slugify a title (Polish transliteration), ensure uniqueness. */
function cms_slugify($title) {
    $s = cms_post_slug_base($title);
    $slug = $s; $n = 2;
    while (is_file(cms_cfg('content_dir') . '/posts/' . $slug . '.md')) {
        $slug = $s . '-' . $n; $n++;
    }
    return $slug;
}

/**
 * Reserve a free post slug with exclusive creation so concurrent requests
 * cannot select the same filename before either post is written.
 */
function cms_reserve_post_slug($title, $maxAttempts = 1000) {
    $base = cms_post_slug_base($title);
    $dir = cms_cfg('content_dir') . '/posts';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { return null; }
    for ($n = 1; $n <= $maxAttempts; $n++) {
        $slug = $n === 1 ? $base : $base . '-' . $n;
        $path = $dir . '/' . $slug . '.md';
        if (is_file($path)) { continue; }
        $lockPath = $path . '.create.lock';
        $lock = @fopen($lockPath, 'x');
        if ($lock === false) { continue; }
        // Recheck after acquiring the lock to coexist safely with external writers.
        if (is_file($path)) {
            fclose($lock);
            @unlink($lockPath);
            continue;
        }
        fwrite($lock, (string) getmypid());
        return array('slug' => $slug, 'path' => $path, 'lock' => $lock, 'lock_path' => $lockPath);
    }
    return null;
}

/** Release a completed or failed slug reservation without leaving a stale lock. */
function cms_release_post_slug_reservation($reservation) {
    if (!is_array($reservation)) { return; }
    if (isset($reservation['lock']) && is_resource($reservation['lock'])) { fclose($reservation['lock']); }
    if (isset($reservation['lock_path'])) { @unlink($reservation['lock_path']); }
}

/* ------------------------------------------------- generated index files */
/** Paths produced from source Markdown by the index regeneration operation. */
function cms_generated_artifact_paths() {
    return array(cms_posts_index_path(), cms_cfg('site_root') . '/search-index.json', cms_cfg('site_root') . '/sitemap.xml');
}

/** Regenerate all derived indexes as one observable, rollback-capable operation. */
function cms_regenerate_indexes() {
    $root = cms_cfg('site_root');
    $site = rtrim(cms_cfg('site_url'), '/');

    // Scan disk once, then reuse the same list for every generated artifact.
    $posts = cms_posts_from_disk();

    $index = array();
    foreach (cms_cfg('search_pages', array()) as $url => $def) {
        $excerpt = '';
        if (!empty($def[2])) {
            $p = cms_region_path($def[2]);
            if ($p && is_file($p)) { $excerpt = cms_excerpt_from(file_get_contents($p), 30); }
        }
        $index[] = array('t' => $def[0], 'u' => $url, 'k' => $def[1], 'e' => $excerpt);
    }
    foreach ($posts as $p) {
        $index[] = array('t' => $p['title'], 'u' => $p['url'],
                         'k' => $p['category_label'] !== '' ? $p['category_label'] : 'Post',
                         'e' => $p['excerpt']);
    }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) { $flags |= JSON_INVALID_UTF8_SUBSTITUTE; }
    $json = json_encode($index, $flags);
    if ($json === false) {
        cms_audit_event('index.search', 'failure', array('reason' => 'json_encode'));
        return array('ok' => false, 'error' => 'index_generation_failed', 'artifact' => 'search-index.json');
    }

    $urls = array_keys(cms_cfg('search_pages', array()));
    foreach ($posts as $p) { $urls[] = $p['url']; }
    foreach (cms_cfg('categories') as $def) { $urls[] = $def[1]; }
    foreach (cms_cfg('sitemap_extra_routes', array()) as $route) { $urls[] = $route; }
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
         . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach (array_unique($urls) as $u) {
        $xml .= '  <url><loc>' . htmlspecialchars($site . $u, ENT_QUOTES, 'UTF-8') . "</loc></url>\n";
    }
    $xml .= "</urlset>\n";
    $paths = cms_generated_artifact_paths();
    return cms_with_resource_lock('generated-indexes', function () use ($paths, $posts, $json, $xml) {
        $snapshot = cms_file_snapshot($paths);
        if ($snapshot === null) { return array('ok' => false, 'error' => 'index_snapshot_failed'); }
        $postsWritten = cms_write_posts_index($posts);
        $writes = array(
            'posts-index.json' => is_array($postsWritten),
            'search-index.json' => cms_atomic_write($paths[1], $json),
            'sitemap.xml' => cms_atomic_write($paths[2], $xml),
        );
        foreach ($writes as $artifact => $ok) {
            if ($ok) { continue; }
            $restored = cms_restore_file_snapshot($snapshot);
            cms_audit_event('index.regenerate', 'failure', array('reason' => 'write_failed', 'artifact' => $artifact, 'rollback' => $restored ? 'success' : 'failure'));
            error_log('CMS: generated artifact write failed (' . $artifact . '); rollback ' . ($restored ? 'succeeded' : 'failed'));
            return array('ok' => false, 'error' => 'index_write_failed', 'artifact' => $artifact, 'rollback' => $restored);
        }
        return array('ok' => true, 'artifacts' => $paths, 'posts' => count($posts));
    });
}

/* -------------------------------------------------------------- editor UI */
/** "＋ Add post" control for a listing page (logged-in only). */
function cms_listing_controls($category) {
    if (!cms_is_logged_in()) { return ''; }
    $cats = cms_cfg('categories');
    if (!isset($cats[$category])) { return ''; }
    return '<div class="cms-listing-controls"><button type="button" class="cms-add-post" data-cms-category="'
         . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '">＋ Add post — '
         . htmlspecialchars($cats[$category][0], ENT_QUOTES, 'UTF-8') . '</button></div>';
}

/** Editor assets — emitted only for a logged-in editor. */
function cms_assets() {
    if (!cms_is_logged_in()) { return ''; }
    $cats = array();
    foreach (cms_cfg('categories') as $slug => $def) { $cats[] = array($slug, $def[0]); }
    $cfg = json_encode(array(
        'api'   => cms_admin_url('api.php'),
        'content' => cms_admin_url('content.php'),
        'media' => cms_admin_url('media.php'),
        'login' => cms_admin_url('login.php'),
        'site' => cms_site_url(),
        'token' => cms_csrf_token(),
        'maxUploadMb' => cms_cfg('max_upload_mb'),
        'categories' => $cats,
        'version' => cms_version(),
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // Load Open Sans with editor assets so in-page authenticated controls match dedicated CMS pages.
    return "\n<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n"
         . "<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n"
         . "<link href=\"https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20,400,1,0&display=swap\" rel=\"stylesheet\">\n"
         . '<link rel="stylesheet" href="' . htmlspecialchars(cms_admin_url('assets/tokens.css'), ENT_QUOTES, 'UTF-8') . "\">\n"
         . '<link rel="stylesheet" href="' . htmlspecialchars(cms_admin_url('assets/editor.css'), ENT_QUOTES, 'UTF-8') . "\">\n"
         . '<script nonce="' . htmlspecialchars(cms_csp_nonce(), ENT_QUOTES, 'UTF-8') . '">window.CMS_CONFIG = ' . $cfg . ";</script>\n"
         . '<script src="' . htmlspecialchars(cms_admin_url('assets/admin-client.js'), ENT_QUOTES, 'UTF-8') . "\" defer></script>\n"
         . '<script src="' . htmlspecialchars(cms_admin_url('assets/editor-state.js'), ENT_QUOTES, 'UTF-8') . "\" defer></script>\n"
         . '<script src="' . htmlspecialchars(cms_admin_url('assets/editor-view.js'), ENT_QUOTES, 'UTF-8') . "\" defer></script>\n"
         . '<script src="' . htmlspecialchars(cms_admin_url('assets/editor.js'), ENT_QUOTES, 'UTF-8') . "\" defer></script>\n";
}
