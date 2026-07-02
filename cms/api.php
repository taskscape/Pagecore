<?php
/**
 * CMS JSON API.
 *
 * GET  ?action=get&key=…            -> {ok, markdown, meta?}
 * POST ?action=preview              -> {ok, html}
 * POST ?action=save                 -> {ok, html}
 * POST ?action=save-post-meta       -> {ok, meta}
 * POST ?action=create-post          -> {ok, slug, url}
 * POST ?action=upload (multipart)   -> {ok, url, kind, markdown}
 * POST ?action=logout               -> redirect /
 *
 * Keys: "page/region" targets content/pages/<page>/<region>.md;
 *       "post:<slug>" targets the body of content/posts/<slug>.md.
 */
require __DIR__ . '/engine.php';
require __DIR__ . '/auth.php';

header('Cache-Control: no-store');

/** Magic-byte MIME sniffing — fallback when the fileinfo extension is absent. */
function cms_sniff_mime($path) {
    $head = (string) @file_get_contents($path, false, null, 0, 512);
    if (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) { return 'image/png'; }
    if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) { return 'image/jpeg'; }
    if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) { return 'image/gif'; }
    if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') { return 'image/webp'; }
    if (strncmp($head, '%PDF-', 5) === 0) { return 'application/pdf'; }
    if (stripos($head, '<svg') !== false || (stripos($head, '<?xml') === 0 && stripos($head, 'svg') !== false)) {
        return 'image/svg+xml';
    }
    return 'application/octet-stream';
}

/** Reject request bodies that are not valid UTF-8 (breaks JSON + files). */
function cms_utf8_or_fail() {
    foreach (func_get_args() as $s) {
        if ($s !== '' && !preg_match('~~u', $s)) {
            cms_fail('Nieprawidłowe kodowanie znaków (wymagany UTF-8).');
        }
    }
}

function cms_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function cms_fail($msg, $code = 400) { cms_json(array('ok' => false, 'error' => $msg), $code); }

/** Resolve an editor key to array(kind, path, slug|null). */
function cms_resolve_key($key, $mustExist) {
    if (strncmp($key, 'post:', 5) === 0) {
        $slug = substr($key, 5);
        $path = cms_post_path($slug, $mustExist);
        return $path ? array('post', $path, $slug) : null;
    }
    $path = cms_region_path($key, $mustExist);
    return $path ? array('region', $path, null) : null;
}

cms_require_auth();

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {

case 'get':
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    $t = cms_resolve_key($key, false);
    if (!$t) { cms_fail('Nieprawidłowy identyfikator fragmentu.'); }
    list($kind, $path, $slug) = $t;
    if (!is_file($path)) {
        if ($kind === 'post') { cms_fail('Nie znaleziono wpisu.', 404); }
        cms_json(array('ok' => true, 'markdown' => ''));
    }
    $raw = file_get_contents($path);
    if ($kind === 'post') {
        list($meta, $body) = cms_parse_front_matter($raw);
        cms_json(array('ok' => true, 'markdown' => $body, 'meta' => $meta));
    }
    cms_json(array('ok' => true, 'markdown' => $raw));

case 'preview':
    $md = isset($_POST['markdown']) ? (string) $_POST['markdown'] : '';
    cms_json(array('ok' => true, 'html' => cms_render_markdown($md)));

case 'save':
    $key = isset($_POST['key']) ? $_POST['key'] : '';
    $md  = isset($_POST['markdown']) ? (string) $_POST['markdown'] : '';
    $md  = str_replace("\r\n", "\n", $md);
    $t = cms_resolve_key($key, false);
    if (!$t) { cms_fail('Nieprawidłowy identyfikator fragmentu.'); }
    list($kind, $path, $slug) = $t;
    if ($kind === 'post') {
        if (!is_file($path)) { cms_fail('Nie znaleziono wpisu.', 404); }
        list($meta, ) = cms_parse_front_matter(file_get_contents($path));
        $data = cms_build_front_matter($meta, $md);
        cms_backup('posts/' . $slug, $path);
    } else {
        $data = $md;
        cms_backup('pages/' . $key, $path);
    }
    if (!cms_atomic_write($path, $data)) {
        error_log('CMS: atomic write failed for ' . $path);
        cms_fail('Zapis nie powiódł się.', 500);
    }
    cms_regenerate_indexes();
    cms_json(array('ok' => true, 'html' => cms_render_markdown($md)));

case 'save-post-meta':
    $slug = isset($_POST['slug']) ? $_POST['slug'] : '';
    $path = cms_post_path($slug, true);
    if (!$path) { cms_fail('Nie znaleziono wpisu.', 404); }
    list($meta, $body) = cms_parse_front_matter(file_get_contents($path));
    $title = trim(isset($_POST['title']) ? (string) $_POST['title'] : '');
    $date  = trim(isset($_POST['date']) ? (string) $_POST['date'] : '');
    $cat   = trim(isset($_POST['category']) ? (string) $_POST['category'] : '');
    $exc   = trim(isset($_POST['excerpt']) ? (string) $_POST['excerpt'] : '');
    cms_utf8_or_fail($title, $exc);
    if ($title === '') { cms_fail('Tytuł jest wymagany.'); }
    if (!preg_match('~^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$~', $date)) { cms_fail('Data musi mieć format RRRR-MM-DD (opcjonalnie z godziną).'); }
    $cats = cms_cfg('categories');
    if (!isset($cats[$cat])) { cms_fail('Nieznana kategoria.'); }
    $meta['title'] = $title;
    $meta['date'] = $date;
    $meta['category'] = $cat;
    if ($exc !== '') { $meta['excerpt'] = $exc; } else { unset($meta['excerpt']); }
    cms_backup('posts/' . $slug, $path);
    if (!cms_atomic_write($path, cms_build_front_matter($meta, $body))) {
        cms_fail('Zapis nie powiódł się.', 500);
    }
    cms_regenerate_indexes();
    cms_json(array('ok' => true, 'meta' => $meta));

case 'create-post':
    $title = trim(isset($_POST['title']) ? (string) $_POST['title'] : '');
    $cat   = trim(isset($_POST['category']) ? (string) $_POST['category'] : '');
    cms_utf8_or_fail($title);
    if ($title === '') { cms_fail('Tytuł jest wymagany.'); }
    $cats = cms_cfg('categories');
    if (!isset($cats[$cat])) { cms_fail('Nieznana kategoria.'); }
    $slug = cms_slugify($title);
    $path = cms_cfg('content_dir') . '/posts/' . $slug . '.md';
    $data = cms_build_front_matter(array(
        'title'    => $title,
        'date'     => date('Y-m-d H:i:s'),
        'category' => $cat,
    ), "Treść wpisu…\n");
    if (!cms_atomic_write($path, $data)) { cms_fail('Nie udało się utworzyć wpisu.', 500); }
    cms_regenerate_indexes();
    cms_json(array('ok' => true, 'slug' => $slug,
        'url' => str_replace('{slug}', $slug, cms_cfg('post_url'))));

case 'upload':
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        cms_fail('Brak pliku.');
    }
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) { cms_fail('Błąd przesyłania (kod ' . $f['error'] . ').'); }
    $maxBytes = cms_cfg('max_upload_mb') * 1024 * 1024;
    if ($f['size'] > $maxBytes) { cms_fail('Plik przekracza limit ' . cms_cfg('max_upload_mb') . ' MB.'); }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, cms_cfg('allowed_ext'), true)) { cms_fail('Niedozwolony typ pliku.'); }

    // MIME sniff — never trust the client. finfo when available,
    // magic-byte fallback for minimal PHP builds.
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($f['tmp_name']);
    } else {
        $mime = cms_sniff_mime($f['tmp_name']);
    }
    $allowedMime = array(
        'jpg' => array('image/jpeg'), 'jpeg' => array('image/jpeg'),
        'png' => array('image/png'), 'gif' => array('image/gif'),
        'webp' => array('image/webp'),
        'svg' => array('image/svg+xml', 'text/xml', 'application/xml', 'text/plain'),
        'pdf' => array('application/pdf'),
    );
    if (!isset($allowedMime[$ext]) || !in_array($mime, $allowedMime[$ext], true)) {
        cms_fail('Zawartość pliku nie odpowiada rozszerzeniu.');
    }
    $isImage = ($ext !== 'pdf');
    if ($isImage && $ext !== 'svg' && @getimagesize($f['tmp_name']) === false) {
        cms_fail('Uszkodzony plik graficzny.');
    }
    if ($ext === 'svg') {
        $svg = file_get_contents($f['tmp_name']);
        if (preg_match('~<script|on[a-z]+\s*=|javascript:~i', $svg)) {
            cms_fail('Plik SVG zawiera niedozwolone elementy.');
        }
    }

    $base = pathinfo($f['name'], PATHINFO_FILENAME);
    $base = strtolower(preg_replace('~[^A-Za-z0-9-]+~', '-', $base));
    $base = trim($base, '-');
    if ($base === '') { $base = 'plik'; }
    $sub = date('Y/m');
    $dir = cms_cfg('uploads_dir') . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { cms_fail('Nie można utworzyć katalogu.', 500); }
    $name = $base . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        cms_fail('Zapis pliku nie powiódł się.', 500);
    }
    $url = cms_cfg('uploads_url') . '/' . $sub . '/' . $name;
    if ($isImage) {
        $snippet = '![' . str_replace(array('[', ']'), '', $base) . '](' . $url . ')';
        $kind = 'image';
    } else {
        $snippet = 'pdf:' . $url . ' "' . str_replace('"', '', $base) . '"';
        $kind = 'pdf';
    }
    cms_json(array('ok' => true, 'url' => $url, 'kind' => $kind, 'markdown' => $snippet));

case 'logout':
    cms_logout();
    header('Location: /');
    exit;

default:
    cms_fail('Nieznana akcja.', 400);
}
