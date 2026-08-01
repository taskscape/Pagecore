<?php
require_once dirname(__DIR__) . '/cms/modules/PathPolicy.php';
require_once __DIR__ . '/lib/WordPressHtmlConverter.php';
require_once __DIR__ . '/lib/WordPressShortcodes.php';
require_once __DIR__ . '/lib/WordPressSqlDump.php';
require_once __DIR__ . '/lib/WordPressImportPolicy.php';
require_once __DIR__ . '/lib/WordPressMenu.php';
/**
 * One-time WordPress -> Pagecore importer.
 *
 * Reads a mysqldump .sql file (no live DB needed), writes one Markdown file per
 * post into content/posts/, maps pages into content/pages/<slug>/body.md, copies
 * only the referenced upload files, imports the WordPress primary navigation
 * tree into content/nav.json, and emits a config fragment with the categories
 * and search pages it discovered.
 *
 * HTML/Gutenberg is converted to Markdown with PHP's built-in DOM extension.
 * The converter emits only its maintained Markdown allowlist. Active elements
 * are dropped or converted to ordinary links, so imports never require raw HTML.
 *
 * Usage (see --help):
 *   php scripts/import-wordpress.php \
 *     --sql=C:/Install/zagozda.eu/mojerzec_zagozdaeu_1784400609.sql \
 *     --uploads-src=C:/Install/zagozda.eu/private_html/wp-content/uploads \
 *     --out-content=C:/Projects/Pagecore/zagozda/content \
 *     --out-uploads=C:/Projects/Pagecore/zagozda/uploads \
 *     --table-prefix=zagozda_ \
 *     --status=publish,private --include-non-public=1 \
 *     --post-url=/post/{slug}/
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

/* ----------------------------------------------------------------- options */
$opt = array(
    'sql' => '',
    'uploads-src' => '',
    'out-content' => '',
    'out-uploads' => '',
    'table-prefix' => 'wp_',
    'status' => 'publish',
    'include-non-public' => '0',
    'post-url' => '/post/{slug}/',
    'uploads-url' => '/uploads',
    'copy-uploads' => '1',
    'dry-run' => '0',
    'force' => '0',
    'max-memory-mb' => '256',
    'max-statement-bytes' => '16777216',
    'self-test-html' => '0',
    'help' => '0',
);
$knownOptions = array_fill_keys(array_keys($opt), true);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') { $opt['help'] = '1'; continue; }
    if ($arg === '--self-test-html') { $opt['self-test-html'] = '1'; continue; }
    if (preg_match('~^--([a-z-]+)=(.*)$~s', $arg, $m)) {
        if (!isset($knownOptions[$m[1]])) { fwrite(STDERR, "Unknown option: --{$m[1]}\n"); exit(2); }
        $opt[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "Unknown argument: $arg\n");
    exit(2);
}
if ($opt['help'] === '1' || ($opt['sql'] === '' && $opt['self-test-html'] !== '1')) {
    fwrite(STDOUT, "WordPress -> Pagecore importer\n\n"
        . "Required:\n"
        . "  --sql=PATH             mysqldump .sql file\n"
        . "  --out-content=DIR      target content/ dir (posts/ and pages/ written here)\n"
        . "Optional:\n"
        . "  --uploads-src=DIR      WordPress wp-content/uploads dir (to copy media)\n"
        . "  --out-uploads=DIR      target uploads/ dir\n"
        . "  --table-prefix=STR     DB table prefix (default wp_)\n"
        . "  --status=LIST          post statuses to import (default publish)\n"
        . "  --include-non-public=1 acknowledge importing non-public posts into editor-only storage\n"
        . "  --post-url=PATTERN     post URL pattern with {slug} (default /post/{slug}/)\n"
        . "  --uploads-url=PATH     public uploads base (default /uploads)\n"
        . "  --copy-uploads=0|1     copy referenced media files (default 1)\n"
        . "  --dry-run=0|1          validate and build staging output without promotion\n"
        . "  --force=0|1            replace a non-empty target after successful staging\n"
        . "  --max-memory-mb=N      importer PHP memory limit (default 256)\n"
        . "  --max-statement-bytes=N maximum accepted SQL INSERT statement (default 16777216)\n"
        . "  --self-test-html       run the HTML allowlist regression and exit\n");
    exit($opt['help'] === '1' ? 0 : 1);
}

$PREFIX = $opt['table-prefix'];
$STATUSES = array_filter(array_map('trim', explode(',', $opt['status'])));
$OUT_CONTENT = rtrim(str_replace('\\', '/', $opt['out-content']), '/');
$OUT_UPLOADS = rtrim(str_replace('\\', '/', $opt['out-uploads']), '/');
$UPLOADS_SRC = rtrim(str_replace('\\', '/', $opt['uploads-src']), '/');
$UPLOADS_URL = rtrim($opt['uploads-url'], '/');
$POST_URL = $opt['post-url'];
$COPY = $opt['copy-uploads'] === '1' && $UPLOADS_SRC !== '' && $OUT_UPLOADS !== '';
$DRY_RUN = $opt['dry-run'] === '1';
$FORCE = $opt['force'] === '1';
$MAX_STATEMENT_BYTES = (int) $opt['max-statement-bytes'];

if ($opt['self-test-html'] !== '1' && $OUT_CONTENT === '') { fwrite(STDERR, "--out-content is required\n"); exit(1); }
if ($opt['self-test-html'] !== '1' && !is_file($opt['sql'])) { fwrite(STDERR, "SQL file not found: {$opt['sql']}\n"); exit(1); }
if ($opt['self-test-html'] !== '1') {
    foreach (array('out-content' => $OUT_CONTENT, 'out-uploads' => $OUT_UPLOADS) as $pathOption => $pathValue) {
        if ($pathValue === '' && $pathOption === 'out-uploads') { continue; }
        if (!preg_match('~^(?:[A-Za-z]:/|/)~', $pathValue)) { fwrite(STDERR, "--$pathOption must be an absolute path\n"); exit(2); }
    }
}
if ($opt['self-test-html'] !== '1' && strpos($POST_URL, '{slug}') === false) {
    fwrite(STDERR, "--post-url must contain the literal {slug} placeholder; quote this argument in PowerShell.\n");
    exit(1);
}
foreach (array('copy-uploads', 'include-non-public', 'dry-run', 'force') as $booleanOption) {
    if (!in_array($opt[$booleanOption], array('0', '1'), true)) { fwrite(STDERR, "--$booleanOption must be 0 or 1\n"); exit(2); }
}
if (!preg_match('~^[A-Za-z0-9_]+$~', $PREFIX)) { fwrite(STDERR, "--table-prefix contains unsupported characters\n"); exit(2); }
if (!preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/{\}-]*$#', $POST_URL) || substr_count($POST_URL, '{slug}') !== 1) {
    fwrite(STDERR, "--post-url must be a root-relative URL with exactly one {slug} placeholder\n"); exit(2);
}
if (!preg_match('#^/(?!/)(?!.*(?:^|/)\.\.?/)[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*$#', $UPLOADS_URL)) {
    fwrite(STDERR, "--uploads-url must be a traversal-free root-relative URL\n"); exit(2);
}
$allowedStatuses = array('publish', 'private', 'draft', 'pending', 'future');
if (!$STATUSES || array_diff($STATUSES, $allowedStatuses)) { fwrite(STDERR, "--status contains an unsupported WordPress status\n"); exit(2); }
if (!ctype_digit($opt['max-memory-mb']) || (int) $opt['max-memory-mb'] < 64 || (int) $opt['max-memory-mb'] > 2048) {
    fwrite(STDERR, "--max-memory-mb must be between 64 and 2048\n"); exit(2);
}
if (!ctype_digit($opt['max-statement-bytes']) || $MAX_STATEMENT_BYTES < 1024 || $MAX_STATEMENT_BYTES > 268435456) {
    fwrite(STDERR, "--max-statement-bytes must be between 1024 and 268435456\n"); exit(2);
}
ini_set('memory_limit', $opt['max-memory-mb'] . 'M');

function say($s) { fwrite(STDOUT, $s . "\n"); }
set_exception_handler(function ($error) { fwrite(STDERR, 'Import failed: ' . $error->getMessage() . "\n"); exit(1); });
function ensure_dir($d) { if (!is_dir($d) && !mkdir($d, 0775, true)) { throw new RuntimeException("mkdir failed: $d"); } }
function write_import_file($path, $data) {
    ensure_dir(dirname($path));
    if (getenv('PAGECORE_IMPORT_FAIL_BASENAME') === basename($path)) { throw new RuntimeException('injected write failure: ' . $path); }
    $bytes = file_put_contents($path, $data, LOCK_EX);
    if ($bytes === false || $bytes !== strlen($data)) { throw new RuntimeException('write failed: ' . $path); }
}
function import_directory_empty($path) {
    if (!is_dir($path)) { return true; }
    $items = scandir($path);
    if ($items === false) { throw new RuntimeException('cannot inspect target: ' . $path); }
    return count(array_diff($items, array('.', '..'))) === 0;
}
function remove_import_tree($path) {
    if (!file_exists($path)) { return; }
    if (is_link($path) || is_file($path)) { if (!unlink($path)) { throw new RuntimeException('cleanup failed: ' . $path); } return; }
    $items = scandir($path);
    if ($items === false) { throw new RuntimeException('cleanup failed: ' . $path); }
    foreach (array_diff($items, array('.', '..')) as $item) { remove_import_tree($path . DIRECTORY_SEPARATOR . $item); }
    if (!rmdir($path)) { throw new RuntimeException('cleanup failed: ' . $path); }
}

/**
 * Rename with a bounded retry.
 *
 * On Windows a directory that was just written can still be held briefly by an
 * indexer or on-access scanner, which surfaces as ERROR_ACCESS_DENIED. Retrying
 * keeps promotion atomic instead of failing a fully staged, verified import.
 */
function rename_import_path($from, $to, $attempts = 20) {
    for ($attempt = 1; ; $attempt++) {
        if (@rename($from, $to)) { return true; }
        if ($attempt >= $attempts) { return false; }
        usleep(100000);
    }
}

/**
 * Copy a target root's server-configuration files into the staged replacement.
 *
 * Promotion swaps whole directories, so without this an import would silently
 * discard the `Require all denied` guards a Pagecore release installs into
 * content/ and uploads/ — leaving private content and media HTTP-reachable.
 */
function carry_forward_guard_files($stage, $target) {
    foreach (array('.htaccess', '.htpasswd', 'web.config') as $guard) {
        $source = $target . DIRECTORY_SEPARATOR . $guard;
        $destination = $stage . DIRECTORY_SEPARATOR . $guard;
        if (!is_file($source) || file_exists($destination)) { continue; }
        if (!copy($source, $destination)) { throw new RuntimeException('could not preserve ' . $guard . ' for ' . $target); }
    }
}

/** Promote all staged roots together, restoring every original root if any rename fails. */
function promote_import_roots(array $pairs, $force, $token) {
    foreach ($pairs as $pair) {
        list($stage, $target) = $pair;
        if (is_dir($target)) { carry_forward_guard_files($stage, $target); }
    }
    $backups = array();
    $promoted = array();
    try {
        foreach ($pairs as $pair) {
            list($stage, $target) = $pair;
            if (file_exists($target)) {
                if (!import_directory_empty($target) && !$force) { throw new RuntimeException('target is not empty; rerun with --force=1: ' . $target); }
                $backup = $target . '.pagecore-backup-' . $token;
                if (!rename_import_path($target, $backup)) { throw new RuntimeException('could not reserve target: ' . $target); }
                $backups[$target] = $backup;
            }
        }
        foreach ($pairs as $pair) {
            list($stage, $target) = $pair;
            if (!rename_import_path($stage, $target)) { throw new RuntimeException('could not promote staged import: ' . $target); }
            $promoted[$target] = $stage;
        }
    } catch (Throwable $error) {
        foreach (array_reverse($promoted, true) as $target => $stage) { if (file_exists($target)) { rename_import_path($target, $stage); } }
        foreach ($backups as $target => $backup) { if (file_exists($backup)) { rename_import_path($backup, $target); } }
        throw $error;
    }
    foreach ($backups as $backup) { remove_import_tree($backup); }
}

/**
 * Keep migration-provided upload paths relative so imported content cannot
 * escape either upload root through traversal, absolute paths, or backslashes.
 */
function safe_upload_rel_path($path) {
    return PagecoreWordPressImportPolicy::uploadRelativePath($path);
}

/** Confirm a resolved filesystem path remains inside its configured root. */
function path_is_within($path, $root) {
    return PagecorePathPolicy::isWithin($path, $root) && PagecorePathPolicy::normalize($path) !== PagecorePathPolicy::normalize($root);
}

/* --------------------------------------------------------- SQL value parser */
/** Extract every row-tuple for a table from all its INSERT statements. */
function sql_rows($sql, $table) {
    return PagecoreWordPressSqlDump::rows($sql, $table);
}

/** Split one row tuple into raw field strings (still quoted/escaped). */
function sql_fields($row) {
    return PagecoreWordPressSqlDump::fields($row);
}

/** Unquote + unescape a single SQL field to its PHP string/NULL. */
function sql_val($s) {
    return PagecoreWordPressSqlDump::value($s);
}

/* ------------------------------------------------------------- URL rewrite */
$GLOBALS['REWRITE_UPLOADS_URL'] = $UPLOADS_URL;
/** Rewrite any WordPress uploads URL (absolute, /blog-prefixed, or relative) to the Pagecore uploads path. */
function rewrite_uploads($text) {
    return PagecoreWordPressImportPolicy::rewriteUploads($text, $GLOBALS['REWRITE_UPLOADS_URL']);
}

/* ------------------------------------------------------ HTML -> Markdown */

/** Stream only requested INSERT statements, bounding any individual statement. */
function sql_rows_from_file($path, array $tables, $maxStatementBytes, array $schemas = array()) {
    return PagecoreWordPressSqlDump::rowsFromFile($path, $tables, $maxStatementBytes, $schemas);
}
if ($opt['self-test-html'] !== '1') {
    foreach ($STATUSES as $requestedStatus) {
        if ($requestedStatus !== 'publish' && $opt['include-non-public'] !== '1') {
            fwrite(STDERR, "Non-public status '$requestedStatus' requires --include-non-public=1.\n");
            exit(1);
        }
    }
}

if ($opt['self-test-html'] === '1') {
    $fixture = '<p>Hello <strong>world</strong>.</p>'
        . '<script>window.CMS_CONFIG.token</script>'
        . '<script data-publication="https://view.example.test/publication/" src="https://evil.example/embed.js"></script>'
        . '<iframe src="https://video.example.test/watch/1" onload="alert(1)"></iframe>'
        . '<object data="/uploads/document.pdf" type="application/pdf"></object>'
        . '<a href="javascript:alert(2)" onclick="alert(3)">unsafe link</a>'
        . '<img src="data:image/svg+xml,evil" onerror="alert(4)" alt="unsafe image">';
    $rendered = (new PagecoreWordPressHtmlConverter($UPLOADS_URL))->convert($fixture);
    if (preg_match('~<(?:script|iframe|object|embed|svg|form)\b|javascript:|\son(?:load|error|click)\s*=~i', $rendered)) {
        fwrite(STDERR, "FAIL: active HTML survived the importer allowlist\n{$rendered}\n");
        exit(1);
    }
    foreach (array('https://view.example.test/publication/', 'https://video.example.test/watch/1', '/uploads/document.pdf') as $safeUrl) {
        if (strpos($rendered, $safeUrl) === false) {
            fwrite(STDERR, "FAIL: safe replacement link missing for {$safeUrl}\n{$rendered}\n");
            exit(1);
        }
    }
    say("PASS: importer HTML allowlist strips active markup and preserves safe replacement links");
    exit(0);
}

/* ------------------------------------------------------------------- load */
say('Reading SQL: ' . $opt['sql']);
$tables = array($PREFIX . 'posts', $PREFIX . 'postmeta', $PREFIX . 'term_relationships',
    $PREFIX . 'term_taxonomy', $PREFIX . 'terms', $PREFIX . 'options', $PREFIX . 'yoast_primary_term');
$streamedRows = sql_rows_from_file($opt['sql'], $tables, $MAX_STATEMENT_BYTES,
    PagecoreWordPressSqlDump::wordPressSchemas($PREFIX));
say('  ' . number_format(filesize($opt['sql'])) . ' bytes streamed (memory limit ' . $opt['max-memory-mb'] . ' MiB)');

$postRows = $streamedRows[$PREFIX . 'posts'];
$metaRows = $streamedRows[$PREFIX . 'postmeta'];
$relRows  = $streamedRows[$PREFIX . 'term_relationships'];
$ttRows   = $streamedRows[$PREFIX . 'term_taxonomy'];
$termRows = $streamedRows[$PREFIX . 'terms'];
$optionRows = $streamedRows[$PREFIX . 'options'];
$primaryRows = $streamedRows[$PREFIX . 'yoast_primary_term'];
unset($streamedRows);
say(sprintf('Rows: posts=%d meta=%d rel=%d term_tax=%d terms=%d options=%d yoast_primary=%d',
    count($postRows), count($metaRows), count($relRows), count($ttRows), count($termRows),
    count($optionRows), count($primaryRows)));

// terms: term_id => [name, slug]
$terms = array();
foreach ($termRows as $r) { $f = sql_fields($r); if (count($f) >= 3) { $terms[sql_val($f[0])] = array(sql_val($f[1]), sql_val($f[2])); } }
// term_taxonomy: tt_id => [term_id, taxonomy]
$tt = array();
foreach ($ttRows as $r) { $f = sql_fields($r); if (count($f) >= 3) { $tt[sql_val($f[0])] = array(sql_val($f[1]), sql_val($f[2])); } }
// term_relationships: object_id => [tt_id,...]
$rel = array();
foreach ($relRows as $r) { $f = sql_fields($r); if (count($f) >= 2) { $rel[sql_val($f[0])][] = sql_val($f[1]); } }
// postmeta: post_id => [key => value]  (only keys we care about)
$WANT_META = array(
    '_thumbnail_id' => 1,
    '_wp_attached_file' => 1,
    '_menu_item_menu_item_parent' => 1,
    '_menu_item_object_id' => 1,
    '_menu_item_object' => 1,
    '_menu_item_type' => 1,
    '_menu_item_url' => 1,
);
$meta = array();
foreach ($metaRows as $r) {
    $f = sql_fields($r);
    if (count($f) < 4) { continue; }
    $pid = sql_val($f[1]); $key = sql_val($f[2]);
    if (isset($WANT_META[$key])) { $meta[$pid][$key] = sql_val($f[3]); }
}
// yoast primary term: post_id => primary category term_id (taxonomy=category)
$primaryCat = array();
foreach ($primaryRows as $r) {
    $f = sql_fields($r);
    // columns: id, post_id, term_id, taxonomy, ...
    if (count($f) >= 4 && sql_val($f[3]) === 'category') { $primaryCat[sql_val($f[1])] = sql_val($f[2]); }
}

// options: option_name => option_value. Used only to identify the menu assigned
// to the active theme's primary location; menu content itself remains in posts.
$options = array();
foreach ($optionRows as $r) {
    $f = sql_fields($r);
    if (count($f) >= 3) { $options[sql_val($f[1])] = sql_val($f[2]); }
}

/** Safely decode a WordPress serialized option, returning null on bad data. */
function wp_unserialize_option($value) {
    return PagecoreWordPressImportPolicy::decodeSerializedOption($value);
}

/* ---- attachments: attach_id => uploads-relative path ---- */
$attachPath = array(); // id => '2019/02/foo.jpg'
foreach ($postRows as $r) {
    $f = sql_fields($r);
    if (count($f) < 21) { continue; }
    if (sql_val($f[20]) !== 'attachment') { continue; }
    $id = sql_val($f[0]);
    if (isset($meta[$id]['_wp_attached_file'])) {
        $attachPath[$id] = ltrim($meta[$id]['_wp_attached_file'], '/');
    } else {
        // fall back to guid
        $guid = sql_val($f[18]);
        if (preg_match('~/wp-content/uploads/(.+)$~', $guid, $mm)) { $attachPath[$id] = $mm[1]; }
    }
}
say('Attachments indexed: ' . count($attachPath));

/** term_id of a post's categories (list of [name,slug]); primary first. */
function post_categories($pid, $rel, $tt, $terms, $primaryCat) {
    $cats = array();
    if (!isset($rel[$pid])) { return $cats; }
    foreach ($rel[$pid] as $ttid) {
        if (!isset($tt[$ttid])) { continue; }
        list($term_id, $taxonomy) = $tt[$ttid];
        if ($taxonomy !== 'category') { continue; }
        if (isset($terms[$term_id])) { $cats[$term_id] = $terms[$term_id]; }
    }
    if (isset($primaryCat[$pid]) && isset($cats[$primaryCat[$pid]])) {
        $pc = $primaryCat[$pid];
        $first = array($pc => $cats[$pc]);
        unset($cats[$pc]);
        $cats = $first + $cats;
    }
    return $cats;
}

/** Tag names (labels) attached to a post, via the post_tag taxonomy. */
function post_tags($pid, $rel, $tt, $terms) {
    $names = array();
    if (!isset($rel[$pid])) { return $names; }
    foreach ($rel[$pid] as $ttid) {
        if (!isset($tt[$ttid])) { continue; }
        list($term_id, $taxonomy) = $tt[$ttid];
        if ($taxonomy !== 'post_tag') { continue; }
        if (isset($terms[$term_id])) { $names[] = $terms[$term_id][0]; }
    }
    return $names;
}


/* ------------------------------------------------------------- conversion */
$FINAL_CONTENT = $OUT_CONTENT;
$FINAL_UPLOADS = $OUT_UPLOADS;
$importToken = bin2hex(random_bytes(8));
$OUT_CONTENT = dirname($FINAL_CONTENT) . DIRECTORY_SEPARATOR . '.pagecore-import-' . $importToken . '-content';
$OUT_UPLOADS = $FINAL_UPLOADS !== '' ? dirname($FINAL_UPLOADS) . DIRECTORY_SEPARATOR . '.pagecore-import-' . $importToken . '-uploads' : '';
$importStages = array($OUT_CONTENT);
if ($COPY) { $importStages[] = $OUT_UPLOADS; }
foreach (array($FINAL_CONTENT, $COPY ? $FINAL_UPLOADS : '') as $target) {
    if ($target !== '' && file_exists($target) && !import_directory_empty($target) && !$FORCE) {
        throw new RuntimeException('target is not empty; rerun with --force=1: ' . $target);
    }
}
register_shutdown_function(function () use (&$importStages) {
    foreach ($importStages as $stage) {
        if (file_exists($stage)) {
            try { remove_import_tree($stage); }
            catch (Throwable $cleanupError) { fwrite(STDERR, 'staging cleanup failed: ' . $cleanupError->getMessage() . "\n"); }
        }
    }
});
ensure_dir($OUT_CONTENT . '/posts');
ensure_dir($OUT_CONTENT . '/pages');

$siteUrls = array();
foreach (array('home', 'siteurl') as $siteUrlOption) {
    if (!empty($options[$siteUrlOption])) { $siteUrls[] = $options[$siteUrlOption]; }
}

$conv = new PagecoreWordPressHtmlConverter($UPLOADS_URL);
// Page-builder shortcodes are expanded to HTML first; $conv then applies the
// same allowlist it uses for ordinary WordPress content.
$shortcodes = new PagecoreWordPressShortcodes($UPLOADS_URL, $attachPath);
$statusSet = array_flip($STATUSES);
$referencedUploads = array(); // uploads-relative path => true
$unsafeUploadRefs = 0;
$categoriesUsed = array();    // slug => name
$pagesForSearch = array();    // url => [title, 'Page', region]
$counts = array('post' => 0, 'page' => 0, 'staged_page' => 0, 'skipped_status' => 0, 'skipped_empty_slug' => 0);
$slugSeen = array();

function unique_slug($slug, &$seen) {
    return PagecoreWordPressImportPolicy::uniqueSlug($slug, $seen);
}

function fm_escape($v) { return PagecoreWordPressImportPolicy::frontMatterValue($v); }

foreach ($postRows as $r) {
    $f = sql_fields($r);
    if (count($f) < 21) { continue; }
    $type = sql_val($f[20]);
    if ($type !== 'post' && $type !== 'page') { continue; }
    $status = sql_val($f[7]);
    if (!isset($statusSet[$status])) { $counts['skipped_status']++; continue; }

    $id      = sql_val($f[0]);
    $date    = substr(sql_val($f[2]), 0, 10);
    $content = sql_val($f[4]);
    $title   = sql_val($f[5]);
    $excerpt = sql_val($f[6]);
    $name    = sql_val($f[11]);

    $slug = PagecoreWordPressImportPolicy::contentSlug($name !== '' ? $name : $title);

    // rewrite upload URLs across raw content first, then convert
    $content = rewrite_uploads($content);
    $content = PagecoreWordPressImportPolicy::rewriteInternalUrls($content, $siteUrls);
    $content = $shortcodes->toHtml($content);
    $bodyMd = $conv->convert($content);

    // collect referenced uploads from the converted markdown
    if (preg_match_all('~' . preg_quote($UPLOADS_URL, '~') . '/([^\s"\'<>()\]]+)~', $bodyMd, $mm)) {
        foreach ($mm[1] as $rp) {
            $safePath = safe_upload_rel_path($rp);
            if ($safePath === null) { $unsafeUploadRefs++; continue; }
            $referencedUploads[$safePath] = true;
        }
    }

    // featured image
    $image = '';
    if (isset($meta[$id]['_thumbnail_id'])) {
        $tid = $meta[$id]['_thumbnail_id'];
        if (isset($attachPath[$tid])) {
            $safePath = safe_upload_rel_path($attachPath[$tid]);
            if ($safePath === null) {
                $unsafeUploadRefs++;
            } else {
                $image = $UPLOADS_URL . '/' . str_replace('%2F', '/', rawurlencode($safePath));
                $referencedUploads[$safePath] = true;
            }
        }
    }

    if ($type === 'page') {
        $slug = unique_slug($slug, $slugSeen);
        if ($status !== 'publish') {
            $reviewDir = $OUT_CONTENT . '/.drafts/imported-pages/' . $slug;
            ensure_dir($reviewDir);
            write_import_file($reviewDir . '/body.md', $bodyMd);
            write_import_file($reviewDir . '/status.txt', fm_escape($status) . "\n");
            $counts['staged_page']++;
            continue;
        }
        $regionDir = $OUT_CONTENT . '/pages/' . $slug;
        ensure_dir($regionDir);
        write_import_file($regionDir . '/body.md', $bodyMd);
        $pagesForSearch['/' . $slug . '/'] = array($title !== '' ? $title : $slug, 'Page', $slug . '/body');
        $counts['page']++;
        continue;
    }

    // post: pick category (primary first, skip 'uncategorized' when a real one exists)
    $cats = post_categories($id, $rel, $tt, $terms, $primaryCat);
    $catSlug = '';
    foreach ($cats as $c) {
        list($cname, $cslug) = $c;
        $cslug = PagecoreSlugPolicy::tagSlug(rawurldecode($cslug !== '' ? $cslug : $cname));
        if ($cslug === '') { continue; }
        if ($catSlug === '' ) { $catSlug = $cslug; $catName = $cname; }
        if ($cslug !== 'uncategorized') { $catSlug = $cslug; $catName = $cname; break; }
    }
    if ($catSlug === '') { $catSlug = 'uncategorized'; $catName = 'Bez kategorii'; }
    $categoriesUsed[$catSlug] = $catName;

    $slug = unique_slug($slug, $slugSeen);

    $fm = "---\n";
    $fm .= 'title: ' . fm_escape($title) . "\n";
    $fm .= 'date: ' . fm_escape($date) . "\n";
    $fm .= 'category: ' . fm_escape($catSlug) . "\n";
    $ex = trim(strip_tags($excerpt));
    if ($ex !== '') { $fm .= 'excerpt: ' . fm_escape($ex) . "\n"; }
    if ($image !== '') { $fm .= 'image: ' . fm_escape($image) . "\n"; }
    $tagNames = post_tags($id, $rel, $tt, $terms);
    if ($tagNames) {
        $tagNames = array_values(array_unique($tagNames));
        $fm .= 'tags: ' . fm_escape(implode(', ', $tagNames)) . "\n";
        $counts['tagged'] = isset($counts['tagged']) ? $counts['tagged'] + 1 : 1;
    }
    if ($status !== 'publish') { $fm .= 'status: ' . fm_escape($status) . "\n"; }
    $fm .= "---\n";

    write_import_file($OUT_CONTENT . '/posts/' . $slug . '.md', $fm . $bodyMd);
    $counts['post']++;
    if ($counts['post'] % 250 === 0) { say('  ...' . $counts['post'] . ' posts'); }
}

say(sprintf('Wrote %d posts (%d tagged), %d public pages, staged %d non-public pages for review (skipped %d by status).',
    $counts['post'], isset($counts['tagged']) ? $counts['tagged'] : 0, $counts['page'], $counts['staged_page'], $counts['skipped_status']));

$menuLabel = '';
$navItems = \PagecoreWordPressMenu\imported_nav_items($postRows, $meta, $rel, $tt, $terms, $options, $POST_URL, $menuLabel);
if ($navItems) {
    $navJson = PagecoreJsonPolicy::encodeStrict($navItems, true);
    write_import_file($OUT_CONTENT . '/nav.json', $navJson . "\n");
    say(sprintf('Wrote navigation: %s (%d top-level items)', $menuLabel, count($navItems)));
} else {
    say('No published WordPress navigation menu found; nav.json not written.');
}

/* --------------------------------------------------------- copy uploads */
if ($COPY) {
    $sourceRoot = realpath($UPLOADS_SRC);
    if ($sourceRoot === false || !is_dir($sourceRoot)) {
        fwrite(STDERR, "Uploads source directory not found: $UPLOADS_SRC\n");
        exit(1);
    }
    ensure_dir($OUT_UPLOADS);
    $destinationRoot = realpath($OUT_UPLOADS);
    if ($destinationRoot === false || !is_dir($destinationRoot)) {
        fwrite(STDERR, "Uploads destination directory could not be resolved: $OUT_UPLOADS\n");
        exit(1);
    }
    say('Copying ' . count($referencedUploads) . ' referenced upload files...');
    $copied = 0; $missing = 0; $rejected = $unsafeUploadRefs;
    foreach (array_keys($referencedUploads) as $rp) {
        // Revalidate at the I/O boundary so every filesystem operation uses a contained relative path.
        $safePath = safe_upload_rel_path($rp);
        if ($safePath === null) { $rejected++; continue; }
        $src = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safePath);
        $srcReal = realpath($src);
        if ($srcReal === false || !is_file($srcReal)) { $missing++; continue; }
        // Resolve symlinks before copying so a link below uploads cannot expose another local file.
        if (!path_is_within($srcReal, $sourceRoot)) { $rejected++; continue; }
        $dst = $destinationRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safePath);
        ensure_dir(dirname($dst));
        $destinationDir = realpath(dirname($dst));
        // Reject destination symlinks and escaped parent directories before an overwrite can occur.
        if ($destinationDir === false || !path_is_within($destinationDir, $destinationRoot) || is_link($dst)) {
            $rejected++;
            continue;
        }
        if (getenv('PAGECORE_IMPORT_FAIL_COPY') === $safePath || !copy($srcReal, $dst)) { throw new RuntimeException('copy failed: ' . $safePath); }
        $copied++;
    }
    if ($missing > 0 || $rejected > 0) { throw new RuntimeException("upload verification failed: missing $missing, rejected $rejected"); }
    say("  copied $copied, missing $missing, rejected unsafe paths $rejected");
} else {
    say('Skipping upload copy (referenced files: ' . count($referencedUploads) . ')');
}

/* ------------------------------------------------ config fragment output */
// order categories by usage count is not tracked here; emit alphabetically-stable
ksort($categoriesUsed);
$catPhp = "    'categories' => array(\n";
foreach ($categoriesUsed as $slug => $name) {
    $catPhp .= sprintf("        %s => array(%s, '/category/%s/'),\n",
        var_export($slug, true), var_export($name, true), $slug);
}
$catPhp .= "    ),\n";

$searchPhp = "    'search_pages' => array(\n";
$searchPhp .= "        '/' => array('Home', 'Listing', null),\n";
foreach ($pagesForSearch as $url => $def) {
    $searchPhp .= sprintf("        %s => array(%s, 'Page', %s),\n",
        var_export($url, true), var_export($def[0], true), var_export($def[2], true));
}
$searchPhp .= "    ),\n";

$sourceHash = hash_file('sha256', $opt['sql']);
if ($sourceHash === false) { throw new RuntimeException('could not hash SQL source'); }
$fragment = "<?php\n// Generated by import-wordpress.php from sha256:" . $sourceHash . "\n"
    . "// Merge these keys into cms/config.php.\n"
    . "return array(\n" . $catPhp . $searchPhp
    . "    'post_url' => " . var_export($POST_URL, true) . ",\n"
    . ");\n";
write_import_file($OUT_CONTENT . '/imported-config-fragment.php', $fragment);
$manifest = array(
    'format' => 1,
    'source_sha256' => $sourceHash,
    'table_prefix' => $PREFIX,
    'statuses' => array_values($STATUSES),
    'post_url' => $POST_URL,
    'uploads_url' => $UPLOADS_URL,
    'counts' => $counts,
    'referenced_uploads' => count($referencedUploads),
);
$manifestJson = PagecoreJsonPolicy::encodeStrict($manifest, true);
write_import_file($OUT_CONTENT . '/import-manifest.json', $manifestJson . "\n");
say('Wrote config fragment and import manifest in staging.');
say('Categories used: ' . count($categoriesUsed) . ' | Pages: ' . count($pagesForSearch));
if ($DRY_RUN) {
    foreach ($importStages as $stage) { remove_import_tree($stage); }
    $importStages = array();
    say('Dry run complete; targets were not changed.');
    exit(0);
}
$promotionPairs = array(array($OUT_CONTENT, $FINAL_CONTENT));
if ($COPY) { $promotionPairs[] = array($OUT_UPLOADS, $FINAL_UPLOADS); }
promote_import_roots($promotionPairs, $FORCE, $importToken);
$importStages = array();
say('Promoted verified import atomically.');
say('Done.');
