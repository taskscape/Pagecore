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
$GLOBALS['CMS_CONFIG'] = require __DIR__ . '/config.php';

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
        $pd->setMarkupEscaped(!cms_cfg('allow_html', true));
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

function cms_excerpt_from($md, $words = 28) {
    $html = preg_replace('~<[^>]+>~', ' ', cms_render_markdown($md));
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('~\s+~u', ' ', $html));
    $parts = preg_split('~ ~u', $text);
    if (count($parts) <= $words) { return $text; }
    return implode(' ', array_slice($parts, 0, $words)) . '…';
}

/** All posts (newest first), optionally filtered by category slug. */
function cms_posts($category = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        $cats = cms_cfg('categories');
        foreach (glob(cms_cfg('content_dir') . '/posts/*.md') as $file) {
            $slug = basename($file, '.md');
            list($meta, $body) = cms_parse_front_matter(file_get_contents($file));
            $cat = isset($meta['category']) ? $meta['category'] : '';
            $cache[] = array(
                'slug'           => $slug,
                'title'          => isset($meta['title']) ? $meta['title'] : $slug,
                'date'           => isset($meta['date']) ? $meta['date'] : '1970-01-01',
                'date_display'   => cms_date_display(isset($meta['date']) ? $meta['date'] : ''),
                'category'       => $cat,
                'category_label' => isset($cats[$cat]) ? $cats[$cat][0] : $cat,
                'excerpt'        => isset($meta['excerpt']) && $meta['excerpt'] !== ''
                                    ? $meta['excerpt'] : cms_excerpt_from($body),
                'url'            => str_replace('{slug}', $slug, cms_cfg('post_url')),
            );
        }
        usort($cache, function ($a, $b) {
            $c = strcmp($b['date'], $a['date']);
            return $c !== 0 ? $c : strcmp($a['slug'], $b['slug']);
        });
    }
    if ($category === null) { return $cache; }
    $out = array();
    foreach ($cache as $p) { if ($p['category'] === $category) { $out[] = $p; } }
    return $out;
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
        'body_md'        => $body,
        'body_html'      => cms_render_markdown($body),
        'url'            => str_replace('{slug}', $slug, cms_cfg('post_url')),
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

    $index = array();
    foreach (cms_cfg('search_pages', array()) as $url => $def) {
        $excerpt = '';
        if (!empty($def[2])) {
            $p = cms_region_path($def[2]);
            if ($p && is_file($p)) { $excerpt = cms_excerpt_from(file_get_contents($p), 30); }
        }
        $index[] = array('t' => $def[0], 'u' => $url, 'k' => $def[1], 'e' => $excerpt);
    }
    foreach (cms_posts() as $p) {
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
    foreach (cms_posts() as $p) { $urls[] = $p['url']; }
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
        'token' => cms_csrf_token(),
        'maxUploadMb' => cms_cfg('max_upload_mb'),
        'categories' => $cats,
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return "\n<link rel=\"stylesheet\" href=\"/cms/assets/editor.css\">\n"
         . "<script>window.CMS_CONFIG = $cfg;</script>\n"
         . "<script src=\"/cms/assets/editor.js\" defer></script>\n";
}
