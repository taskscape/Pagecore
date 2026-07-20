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
 * PHP 7.4+ compatible (no PHP 8-only syntax).
 */

if (defined('CMS_LOADED')) { return; }
define('CMS_LOADED', 1);

define('CMS_DIR', __DIR__);
$cmsConfigFile = defined('CMS_CONFIG_FILE') ? CMS_CONFIG_FILE : getenv('PAGECORE_CONFIG');
if (!$cmsConfigFile) { $cmsConfigFile = __DIR__ . '/config.php'; }
$GLOBALS['CMS_CONFIG'] = require $cmsConfigFile;

/* ---------------------------------------------------------------- session */
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name(cms_cfg('session_name'));
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    ini_set('session.use_strict_mode', '1');
    session_start();

    // absolute session lifetime
    if (!empty($_SESSION['cms_auth'])) {
        $maxAge = cms_cfg('session_hours') * 3600;
        if (empty($_SESSION['cms_auth_at']) || time() - $_SESSION['cms_auth_at'] > $maxAge) {
            unset($_SESSION['cms_auth'], $_SESSION['cms_auth_at'], $_SESSION['cms_csrf']);
        }
    }
}

/* ----------------------------------------------------------------- config */
function cms_cfg($key, $default = null) {
    $c = $GLOBALS['CMS_CONFIG'];
    return array_key_exists($key, $c) ? $c[$key] : $default;
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
    $path = cms_cfg('content_dir') . '/pages/' . $key . '.md';
    if ($mustExist && !is_file($path)) { return null; }
    $dir = dirname($path);
    if (is_dir($dir)) {
        $real = realpath($dir);
        $base = realpath(cms_cfg('content_dir'));
        if ($real === false || $base === false || strpos($real, $base) !== 0) { return null; }
    }
    return $path;
}

/** Validate a post slug and resolve to content/posts/<slug>.md. */
function cms_post_path($slug, $mustExist = false) {
    if (!preg_match('~^[a-z0-9-]+$~', $slug)) { return null; }
    $path = cms_cfg('content_dir') . '/posts/' . $slug . '.md';
    if ($mustExist && !is_file($path)) { return null; }
    return $path;
}

/* -------------------------------------------------------- atomic file I/O */
/** Write atomically (tmp + rename); Windows-safe (unlink-then-rename retry). */
function cms_atomic_write($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { return false; }
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $data) === false) { return false; }
    if (@rename($tmp, $path)) { return true; }
    // Windows: rename may fail when the target exists
    @unlink($path);
    if (@rename($tmp, $path)) { return true; }
    @unlink($tmp);
    return false;
}

/** Back up the current file for a key ("pages/foo/bar" or "posts/slug"). */
function cms_backup($relKey, $path) {
    if (!is_file($path)) { return; }
    $bdir = cms_cfg('backup_dir') . '/' . dirname($relKey);
    if (!is_dir($bdir)) { @mkdir($bdir, 0775, true); }
    $name = basename($relKey) . '.' . date('Ymd-His') . '.' . substr(bin2hex(random_bytes(2)), 0, 4) . '.md';
    @copy($path, $bdir . '/' . $name);
    // prune to the newest N
    $keep = (int) cms_cfg('backup_keep', 20);
    $files = glob($bdir . '/' . basename($relKey) . '.*.md');
    if ($files && count($files) > $keep) {
        sort($files); // timestamped names sort chronologically
        foreach (array_slice($files, 0, count($files) - $keep) as $old) { @unlink($old); }
    }
}

function cms_target_rel_key($kind, $id) {
    return $kind === 'post' ? 'posts/' . $id : 'pages/' . $id;
}

function cms_draft_region_path($key, $mustExist = false) {
    if (!preg_match('~^[a-z0-9-]+(/[a-z0-9-]+){0,2}$~', $key)) { return null; }
    $path = cms_cfg('content_dir') . '/.drafts/pages/' . $key . '.md';
    if ($mustExist && !is_file($path)) { return null; }
    return $path;
}

function cms_draft_post_path($slug, $mustExist = false) {
    if (!preg_match('~^[a-z0-9-]+$~', $slug)) { return null; }
    $path = cms_cfg('content_dir') . '/.drafts/posts/' . $slug . '.md';
    if ($mustExist && !is_file($path)) { return null; }
    return $path;
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
        $items = @scandir($dir);
        if ($items === false || count($items) > 2) { break; }
        if (!@rmdir($dir)) { break; }
        $dir = dirname($dir);
    }
}

function cms_clear_draft($kind, $id) {
    $path = cms_draft_path($kind, $id, true);
    if (!$path) { return; }
    @unlink($path);
    cms_remove_empty_dirs(dirname($path), cms_cfg('content_dir') . '/.drafts');
}

function cms_revision_id($file) {
    $base = realpath(cms_cfg('backup_dir'));
    $real = realpath($file);
    if ($base === false || $real === false) { return null; }
    $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
    $real = str_replace('\\', '/', $real);
    if (strpos($real, $base) !== 0) { return null; }
    return substr($real, strlen($base));
}

function cms_revision_path($id) {
    if (!preg_match('~^[A-Za-z0-9._/-]+\.md$~', (string) $id)) { return null; }
    if (strpos($id, '..') !== false) { return null; }
    $path = cms_cfg('backup_dir') . '/' . str_replace('/', DIRECTORY_SEPARATOR, $id);
    if (!is_file($path)) { return null; }
    $base = realpath(cms_cfg('backup_dir'));
    $real = realpath($path);
    if ($base === false || $real === false) { return null; }
    $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
    $real = str_replace('\\', '/', $real);
    return strpos($real, $base) === 0 ? $path : null;
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
    return array_map('strtolower', cms_cfg('allowed_ext', array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf')));
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
    $base = rtrim(cms_cfg('uploads_dir'), '/\\');
    $path = $base . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if ($mustExist && !is_file($path)) { return null; }
    $baseReal = realpath($base);
    $dirReal = realpath(dirname($path));
    if ($baseReal === false || $dirReal === false) { return null; }
    $baseNorm = rtrim(str_replace('\\', '/', $baseReal), '/') . '/';
    $dirNorm = rtrim(str_replace('\\', '/', $dirReal), '/') . '/';
    if (strpos($dirNorm, $baseNorm) !== 0 && $dirNorm !== $baseNorm) { return null; }
    return $path;
}

function cms_media_rel_from_path($path) {
    $base = realpath(cms_cfg('uploads_dir'));
    $real = realpath($path);
    if ($base === false || $real === false) { return null; }
    $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
    $real = str_replace('\\', '/', $real);
    if (strpos($real, $base) !== 0) { return null; }
    return substr($real, strlen($base));
}

function cms_media_url($rel) {
    return rtrim(cms_cfg('uploads_url'), '/') . '/' . str_replace('%2F', '/', rawurlencode(str_replace('\\', '/', $rel)));
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

function cms_media_assets($query = '') {
    $base = cms_cfg('uploads_dir');
    if (!is_dir($base)) { return array(); }
    $query = strtolower(trim((string) $query));
    $files = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) { continue; }
        $rel = cms_media_rel_from_path($file->getPathname());
        if ($rel === null || !cms_media_is_valid_rel($rel)) { continue; }
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
    return $files;
}

function cms_media_is_referenced($url) {
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
            if (strpos((string) file_get_contents($file->getPathname()), $url) !== false) {
                return true;
            }
        }
    }
    return false;
}

/* ------------------------------------------------------------ front matter */
/** Parse "---\nkey: value\n---\nbody" into array(meta, body). */
function cms_parse_front_matter($raw) {
    $meta = array();
    $body = $raw;
    if (strncmp($raw, "---", 3) === 0) {
        $end = strpos($raw, "\n---", 3);
        if ($end !== false) {
            $head = substr($raw, 3, $end - 3);
            $body = ltrim(substr($raw, $end + 4), "\r\n");
            foreach (preg_split('~\r?\n~', $head) as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, ':') === false) { continue; }
                list($k, $v) = explode(':', $line, 2);
                $meta[trim($k)] = trim($v);
            }
        }
    }
    return array($meta, $body);
}

function cms_build_front_matter(array $meta, $body) {
    $out = "---\n";
    foreach ($meta as $k => $v) {
        $out .= $k . ': ' . str_replace(array("\r", "\n"), ' ', (string) $v) . "\n";
    }
    return $out . "---\n" . $body;
}

/* -------------------------------------------------------------- rendering */
function cms_parsedown() {
    static $pd = null;
    if ($pd === null) {
        require_once CMS_DIR . '/lib/Parsedown.php';
        $pd = new Parsedown();
        $pd->setBreaksEnabled(false);
        // Editor-authored Markdown is untrusted by default. Parsedown safe
        // mode escapes raw HTML and also neutralizes unsafe Markdown URLs.
        // Integrations that have a separate HTML sanitizer can deliberately
        // restore legacy raw-HTML rendering with allow_html => true.
        $pd->setSafeMode(!cms_cfg('allow_html', false));
        $pd->setUrlsLinked(false);
    }
    return $pd;
}

/**
 * Markdown -> HTML: Parsedown, then post-processing:
 *  - "pdf:/uploads/x.pdf \"Label\"" paragraphs -> <object> embed + fallback
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
            return '<p class="pdf-embed"><object data="' . $url . '" type="application/pdf" width="100%" height="820">'
                 . '<a href="' . $url . '">Pobierz dokument PDF (' . $lab . ')</a></object></p>' . "\n"
                 . '<p class="pdf-fallback"><a href="' . $url . '">Otwórz / pobierz PDF: ' . $lab . '</a></p>';
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
        $html = '<p class="cms-empty">(pusty fragment — kliknij, aby edytować)</p>';
    }
    return '<' . $tag . ' class="cms-editable" data-cms-key="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">'
         . $html . '</' . $tag . '>';
}

/* ------------------------------------------------------------------ posts */
/** Polish month names for the site's "j F Y" date format. */
function cms_date_display($iso) {
    static $months = array('', 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca',
        'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia');
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
    $pattern = (string) cms_cfg('post_url', '/post/{slug}/');
    if (strpos($pattern, '{slug}') === false) {
        $pattern = rtrim($pattern, '/') . '/{slug}/';
    }
    return str_replace('{slug}', (string) $slug, $pattern);
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
        list($meta, $body) = cms_parse_front_matter(file_get_contents($file));
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
        );
    }
    usort($list, function ($a, $b) {
        $c = strcmp($b['date'], $a['date']);
        return $c !== 0 ? $c : strcmp($a['slug'], $b['slug']);
    });
    return $list;
}

/** Write the cached posts index; returns the list that was written. */
function cms_write_posts_index($list = null) {
    if ($list === null) { $list = cms_posts_from_disk(); }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) { $flags |= JSON_INVALID_UTF8_SUBSTITUTE; }
    $json = json_encode($list, $flags);
    if ($json !== false) {
        cms_atomic_write(cms_posts_index_path(), $json);
    } else {
        error_log('CMS: posts index json_encode failed — index left unchanged');
    }
    return $list;
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
            if (is_array($decoded)) { $cache = $decoded; }
        }
        if ($cache === false) {
            $cache = cms_write_posts_index(cms_posts_from_disk());
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
    $total = count($all);
    $per_page = max(1, (int) $per_page);
    $pages = (int) max(1, ceil($total / $per_page));
    $page = (int) $page;
    if ($page < 1) { $page = 1; }
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $per_page;
    return array(
        'items'    => array_slice($all, $offset, $per_page),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => $pages,
        'has_prev' => $page > 1,
        'has_next' => $page < $pages,
    );
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

/** One post with rendered body; null when unknown. */
function cms_post($slug) {
    $path = cms_post_path($slug, true);
    if (!$path) { return null; }
    list($meta, $body) = cms_parse_front_matter(file_get_contents($path));
    $cats = cms_cfg('categories');
    $cat = isset($meta['category']) ? $meta['category'] : '';
    return array(
        'slug'           => $slug,
        'title'          => isset($meta['title']) ? $meta['title'] : $slug,
        'date'           => isset($meta['date']) ? $meta['date'] : '',
        'date_display'   => cms_date_display(isset($meta['date']) ? $meta['date'] : ''),
        'category'       => $cat,
        'category_label' => isset($cats[$cat]) ? $cats[$cat][0] : $cat,
        'lead'           => isset($meta['excerpt']) ? $meta['excerpt'] : '',
        'image'          => isset($meta['image']) ? $meta['image'] : '',
        'mins'           => cms_reading_minutes($body),
        'tags'           => cms_parse_tags(isset($meta['tags']) ? $meta['tags'] : ''),
        'body_md'        => $body,
        'body_html'      => cms_render_markdown($body),
        'url'            => cms_post_url($slug),
    );
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
    $base = realpath($base);
    $real = realpath($path);
    if ($base === false || $real === false) { return null; }
    $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
    $real = str_replace('\\', '/', $real);
    if (strpos($real, $base) !== 0) { return null; }
    return substr($real, strlen($base));
}

function cms_region_files() {
    $base = cms_cfg('content_dir') . '/pages';
    if (!is_dir($base)) { return array(); }
    $out = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') { continue; }
        $rel = cms_content_rel_path($file->getPathname(), $base);
        if ($rel === null) { continue; }
        $key = preg_replace('~\.md$~i', '', str_replace('\\', '/', $rel));
        if (cms_region_path($key, true)) { $out[] = $key; }
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
    }
    $out = array_keys($keys);
    sort($out, SORT_STRING);
    return $out;
}

function cms_content_file_summary($path) {
    if (!is_file($path)) { return array('exists' => false, 'size' => 0, 'modified' => null); }
    return array('exists' => true, 'size' => filesize($path), 'modified' => filemtime($path));
}

function cms_content_inventory() {
    $searchPages = cms_cfg('search_pages', array());
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
        );
        $regions[] = $item;
        if (!$item['exists']) { $missing[] = $item; }
    }

    $posts = cms_posts();
    $counts = array();
    foreach (cms_cfg('categories', array()) as $slug => $def) { $counts[$slug] = 0; }
    foreach ($posts as $post) {
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
        'categories' => $categories,
        'nav' => array(
            'file' => cms_nav_file(),
            'exists' => is_file(cms_nav_file()),
            'items' => cms_nav_items(),
            'json' => cms_nav_json(),
        ),
    );
}

/** Slugify a title (Polish transliteration), ensure uniqueness. */
function cms_slugify($title) {
    $map = array(
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
        'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z',
    );
    $s = strtr((string) $title, $map);
    $s = strtolower($s);
    $s = preg_replace('~[^a-z0-9]+~', '-', $s);
    $s = trim($s, '-');
    if ($s === '') { $s = 'wpis'; }
    $slug = $s; $n = 2;
    while (is_file(cms_cfg('content_dir') . '/posts/' . $slug . '.md')) {
        $slug = $s . '-' . $n; $n++;
    }
    return $slug;
}

/* ------------------------------------------------- generated index files */
/** Regenerate search-index.json and sitemap.xml after content changes. */
function cms_regenerate_indexes() {
    $root = cms_cfg('site_root');
    $site = rtrim(cms_cfg('site_url'), '/');

    // Scan disk once, then reuse the same list for every generated artifact.
    $posts = cms_posts_from_disk();
    cms_write_posts_index($posts);

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
                         'k' => $p['category_label'] !== '' ? $p['category_label'] : 'Wpis',
                         'e' => $p['excerpt']);
    }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) { $flags |= JSON_INVALID_UTF8_SUBSTITUTE; }
    $json = json_encode($index, $flags);
    if ($json !== false) {
        cms_atomic_write($root . '/search-index.json', $json);
    } else {
        error_log('CMS: search index json_encode failed — index left unchanged');
    }

    $urls = array_keys(cms_cfg('search_pages', array()));
    foreach ($posts as $p) { $urls[] = $p['url']; }
    foreach (cms_cfg('categories') as $def) { $urls[] = $def[1]; }
    $urls[] = '/szukaj/';
    $urls[] = '/kontakt/';
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
         . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach (array_unique($urls) as $u) {
        $xml .= '  <url><loc>' . htmlspecialchars($site . $u, ENT_QUOTES, 'UTF-8') . "</loc></url>\n";
    }
    $xml .= "</urlset>\n";
    cms_atomic_write($root . '/sitemap.xml', $xml);
}

/* -------------------------------------------------------------- editor UI */
/** "＋ Dodaj wpis" control for a listing page (logged-in only). */
function cms_listing_controls($category) {
    if (!cms_is_logged_in()) { return ''; }
    $cats = cms_cfg('categories');
    if (!isset($cats[$category])) { return ''; }
    return '<div class="cms-listing-controls"><button type="button" class="cms-add-post" data-cms-category="'
         . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '">＋ Dodaj wpis — '
         . htmlspecialchars($cats[$category][0], ENT_QUOTES, 'UTF-8') . '</button></div>';
}

/** Editor assets — emitted only for a logged-in editor. */
function cms_assets() {
    if (!cms_is_logged_in()) { return ''; }
    $cats = array();
    foreach (cms_cfg('categories') as $slug => $def) { $cats[] = array($slug, $def[0]); }
    $cfg = json_encode(array(
        'api'   => '/cms/api.php',
        'content' => '/cms/content.php',
        'media' => '/cms/media.php',
        'token' => cms_csrf_token(),
        'maxUploadMb' => cms_cfg('max_upload_mb'),
        'categories' => $cats,
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return "\n<link rel=\"stylesheet\" href=\"/cms/assets/editor.css\">\n"
         . "<script>window.CMS_CONFIG = $cfg;</script>\n"
         . "<script src=\"/cms/assets/editor.js\" defer></script>\n";
}
