<?php
/**
 * CMS JSON API.
 *
 * GET  ?action=get&key=…            -> {ok, markdown, meta?}
 * GET  ?action=revisions&key=…      -> {ok, revisions[]}
 * GET  ?action=media-list&q=…       -> {ok, assets[]}
 * GET  ?action=content-inventory    -> {ok, inventory}
 * GET  ?action=version              -> {ok, version}
 * GET  ?action=preview-draft&key=…  -> standalone HTML preview of saved draft
 * POST ?action=preview              -> {ok, html}
 * POST ?action=save                 -> {ok, html}
 * POST ?action=save-draft           -> {ok, draft}
 * POST ?action=publish              -> {ok, html, markdown, meta?}
 * POST ?action=discard-draft        -> {ok, markdown, meta?}
 * POST ?action=restore              -> {ok, html, markdown, meta?}
 * POST ?action=save-post-meta       -> {ok, meta}
 * POST ?action=create-post          -> {ok, slug, url}
 * POST ?action=save-media-meta      -> {ok, asset}
 * POST ?action=delete-media         -> {ok}
 * POST ?action=save-nav             -> {ok, nav}
 * POST ?action=create-region        -> {ok, key}
 * POST ?action=upload (multipart)   -> {ok, url, kind, markdown, asset}
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

function cms_preview_url($key) {
    return '/cms/api.php?action=preview-draft&key=' . rawurlencode($key);
}

function cms_editor_payload($kind, $path) {
    $raw = is_file($path) ? file_get_contents($path) : '';
    if ($kind === 'post') {
        list($meta, $body) = cms_parse_front_matter($raw);
        return array(
            'markdown' => $body,
            'meta'     => $meta,
            'html'     => cms_render_markdown($body),
        );
    }
    return array(
        'markdown' => $raw,
        'html'     => cms_render_markdown($raw),
    );
}

function cms_draft_payload($kind, $id, $key) {
    $path = cms_draft_path($kind, $id, true);
    if (!$path) { return null; }
    $payload = cms_editor_payload($kind, $path);
    $payload['updated'] = date('Y-m-d H:i:s', filemtime($path));
    $payload['preview_url'] = cms_preview_url($key);
    return $payload;
}

function cms_post_meta_from_request(array $meta, $strict) {
    $title = trim(isset($_POST['title']) ? (string) $_POST['title'] : (isset($meta['title']) ? $meta['title'] : ''));
    $date  = trim(isset($_POST['date']) ? (string) $_POST['date'] : (isset($meta['date']) ? $meta['date'] : ''));
    $cat   = trim(isset($_POST['category']) ? (string) $_POST['category'] : (isset($meta['category']) ? $meta['category'] : ''));
    $exc   = trim(isset($_POST['excerpt']) ? (string) $_POST['excerpt'] : (isset($meta['excerpt']) ? $meta['excerpt'] : ''));
    $img   = trim(isset($_POST['image']) ? (string) $_POST['image'] : (isset($meta['image']) ? $meta['image'] : ''));
    $tags  = trim(isset($_POST['tags']) ? (string) $_POST['tags'] : (isset($meta['tags']) ? $meta['tags'] : ''));
    cms_utf8_or_fail($title, $exc);
    cms_utf8_or_fail($tags, $tags);
    if ($strict) {
        if ($title === '') { cms_fail('Tytuł jest wymagany.'); }
        if (!preg_match('~^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$~', $date)) {
            cms_fail('Data musi mieć format RRRR-MM-DD (opcjonalnie z godziną).');
        }
        $cats = cms_cfg('categories');
        if (!isset($cats[$cat])) { cms_fail('Nieznana kategoria.'); }
    }
    if ($strict && $img !== '' && !preg_match('~^(/|https?://)[^\s"<>]+$~', $img)) {
        cms_fail('Obrazek musi być ścieżką zaczynającą się od / lub adresem http(s).');
    }
    $meta['title'] = $title;
    $meta['date'] = $date;
    $meta['category'] = $cat;
    if ($exc !== '') { $meta['excerpt'] = $exc; } else { unset($meta['excerpt']); }
    if ($img !== '') { $meta['image'] = $img; } else { unset($meta['image']); }
    // normalise tags to a clean, de-duplicated "A, B, C" string
    if ($tags !== '') {
        $parsed = cms_parse_tags($tags);
        $labels = array();
        foreach ($parsed as $t) { $labels[] = $t['label']; }
        if ($labels) { $meta['tags'] = implode(', ', $labels); } else { unset($meta['tags']); }
    } else {
        unset($meta['tags']);
    }
    return $meta;
}

function cms_current_post_meta($path) {
    if (!is_file($path)) { return array(); }
    list($meta, ) = cms_parse_front_matter(file_get_contents($path));
    return $meta;
}

function cms_write_editor_content($kind, $id, $path, $markdown, ?array $meta = null) {
    $markdown = str_replace("\r\n", "\n", $markdown);
    if ($kind === 'post') {
        if (!is_file($path)) { cms_fail('Nie znaleziono wpisu.', 404); }
        $data = cms_build_front_matter($meta === null ? cms_current_post_meta($path) : $meta, $markdown);
    } else {
        $data = $markdown;
    }
    $relKey = cms_target_rel_key($kind, $id);
    cms_backup($relKey, $path);
    if (!cms_atomic_write($path, $data)) {
        error_log('CMS: atomic write failed for ' . $path);
        cms_fail('Zapis nie powiódł się.', 500);
    }
    cms_clear_draft($kind, $id);
    cms_regenerate_indexes();
    return cms_editor_payload($kind, $path);
}

function cms_preview_page($key, $kind, array $payload) {
    $title = $kind === 'post' && !empty($payload['meta']['title'])
        ? $payload['meta']['title']
        : 'Podgląd szkicu: ' . $key;
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>body{margin:0;background:#faf8f3;color:#2b2620;font:16px/1.6 -apple-system,"Segoe UI",Roboto,Arial,sans-serif}'
       . 'main{max-width:860px;margin:0 auto;padding:40px 24px 64px}.cms-preview-note{margin:0 0 24px;color:#8a8072;font-size:13px}'
       . 'h1{font-size:32px;line-height:1.2;margin:0 0 12px}.cms-preview-meta{color:#8a8072;margin:0 0 28px}'
       . 'img{max-width:100%;height:auto}.cms-table{border-collapse:collapse;width:100%;margin:16px 0}'
       . '.cms-table th,.cms-table td{border:1px solid #d8d2c4;padding:8px 12px;text-align:left}'
       . '</style></head><body><main>';
    echo '<p class="cms-preview-note">Podgląd zapisanego szkicu. Ta strona jest widoczna tylko po zalogowaniu do CMS.</p>';
    if ($kind === 'post') {
        echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
        $date = isset($payload['meta']['date']) ? $payload['meta']['date'] : '';
        $cat = isset($payload['meta']['category']) ? $payload['meta']['category'] : '';
        if ($date !== '' || $cat !== '') {
            echo '<p class="cms-preview-meta">' . htmlspecialchars(trim($date . ' ' . $cat), ENT_QUOTES, 'UTF-8') . '</p>';
        }
    }
    echo $payload['html'];
    echo '</main></body></html>';
    exit;
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
        $payload = array('ok' => true, 'markdown' => '', 'html' => '');
        $draft = cms_draft_payload($kind, $key, $key);
        if ($draft) { $payload['draft'] = $draft; }
        cms_json($payload);
    }
    $id = $kind === 'post' ? $slug : $key;
    $payload = cms_editor_payload($kind, $path);
    $payload['ok'] = true;
    $draft = cms_draft_payload($kind, $id, $key);
    if ($draft) { $payload['draft'] = $draft; }
    cms_json($payload);

case 'revisions':
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    $t = cms_resolve_key($key, false);
    if (!$t) { cms_fail('Nieprawidłowy identyfikator fragmentu.'); }
    list($kind, , $slug) = $t;
    $id = $kind === 'post' ? $slug : $key;
    cms_json(array('ok' => true, 'revisions' => cms_revisions(cms_target_rel_key($kind, $id))));

case 'media-list':
    $query = isset($_GET['q']) ? (string) $_GET['q'] : '';
    cms_utf8_or_fail($query);
    cms_json(array('ok' => true, 'assets' => cms_media_assets($query)));

case 'content-inventory':
    cms_json(array('ok' => true, 'inventory' => cms_content_inventory()));

case 'version':
    cms_json(array('ok' => true, 'version' => cms_version()));

case 'preview-draft':
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    $t = cms_resolve_key($key, false);
    if (!$t) { cms_fail('Nieprawidłowy identyfikator fragmentu.'); }
    list($kind, , $slug) = $t;
    $id = $kind === 'post' ? $slug : $key;
    $draftPath = cms_draft_path($kind, $id, true);
    if (!$draftPath) { cms_fail('Nie znaleziono szkicu.', 404); }
    cms_preview_page($key, $kind, cms_editor_payload($kind, $draftPath));

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
    cms_clear_draft($kind, $kind === 'post' ? $slug : $key);
    cms_regenerate_indexes();
    cms_json(array('ok' => true, 'html' => cms_render_markdown($md)));

case 'save-draft':
    $key = isset($_POST['key']) ? $_POST['key'] : '';
    $md  = isset($_POST['markdown']) ? (string) $_POST['markdown'] : '';
    $md  = str_replace("\r\n", "\n", $md);
    $t = cms_resolve_key($key, false);
    if (!$t) { cms_fail('Nieprawidłowy identyfikator fragmentu.'); }
    list($kind, $path, $slug) = $t;
    $id = $kind === 'post' ? $slug : $key;
    $draftPath = cms_draft_path($kind, $id, false);
    if (!$draftPath) { cms_fail('Nieprawidłowy identyfikator szkicu.'); }
    if ($kind === 'post') {
        if (!is_file($path)) { cms_fail('Nie znaleziono wpisu.', 404); }
        $basePath = cms_draft_path($kind, $id, true);
        $meta = cms_current_post_meta($basePath ? $basePath : $path);
        $data = cms_build_front_matter(cms_post_meta_from_request($meta, false), $md);
    } else {
        $data = $md;
    }
    if (!cms_atomic_write($draftPath, $data)) {
        cms_fail('Nie udało się zapisać szkicu.', 500);
    }
    cms_json(array('ok' => true, 'draft' => cms_draft_payload($kind, $id, $key)));

case 'publish':
    $key = isset($_POST['key']) ? $_POST['key'] : '';
    $md  = isset($_POST['markdown']) ? (string) $_POST['markdown'] : '';
    $t = cms_resolve_key($key, false);
    if (!$t) { cms_fail('Nieprawidłowy identyfikator fragmentu.'); }
    list($kind, $path, $slug) = $t;
    $id = $kind === 'post' ? $slug : $key;
    $meta = null;
    if ($kind === 'post') {
        $meta = cms_post_meta_from_request(cms_current_post_meta($path), true);
    }
    $payload = cms_write_editor_content($kind, $id, $path, $md, $meta);
    $payload['ok'] = true;
    cms_json($payload);

case 'discard-draft':
    $key = isset($_POST['key']) ? $_POST['key'] : '';
    $t = cms_resolve_key($key, false);
    if (!$t) { cms_fail('Nieprawidłowy identyfikator fragmentu.'); }
    list($kind, $path, $slug) = $t;
    $id = $kind === 'post' ? $slug : $key;
    cms_clear_draft($kind, $id);
    $payload = cms_editor_payload($kind, $path);
    $payload['ok'] = true;
    cms_json($payload);

case 'restore':
    $key = isset($_POST['key']) ? $_POST['key'] : '';
    $revision = isset($_POST['revision']) ? (string) $_POST['revision'] : '';
    $t = cms_resolve_key($key, false);
    if (!$t) { cms_fail('Nieprawidłowy identyfikator fragmentu.'); }
    list($kind, $path, $slug) = $t;
    $id = $kind === 'post' ? $slug : $key;
    $relKey = cms_target_rel_key($kind, $id);
    if (!cms_revision_belongs_to($revision, $relKey)) { cms_fail('Ta kopia nie pasuje do edytowanego fragmentu.'); }
    $revisionPath = cms_revision_path($revision);
    if (!$revisionPath) { cms_fail('Nie znaleziono wybranej kopii.', 404); }
    cms_backup($relKey, $path);
    if (!cms_atomic_write($path, file_get_contents($revisionPath))) {
        cms_fail('Nie udało się przywrócić kopii.', 500);
    }
    cms_clear_draft($kind, $id);
    cms_regenerate_indexes();
    $payload = cms_editor_payload($kind, $path);
    $payload['ok'] = true;
    cms_json($payload);

case 'save-post-meta':
    $slug = isset($_POST['slug']) ? $_POST['slug'] : '';
    $path = cms_post_path($slug, true);
    if (!$path) { cms_fail('Nie znaleziono wpisu.', 404); }
    list($meta, $body) = cms_parse_front_matter(file_get_contents($path));
    $meta = cms_post_meta_from_request($meta, true);
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

case 'save-nav':
    $raw = isset($_POST['json']) ? (string) $_POST['json'] : '';
    cms_utf8_or_fail($raw);
    $error = null;
    if (!cms_write_nav_json($raw, $error)) {
        cms_fail($error ? $error : 'Nie udało się zapisać nawigacji.');
    }
    cms_json(array('ok' => true, 'nav' => cms_nav_items(), 'json' => cms_nav_json()));

case 'create-region':
    $key = trim(isset($_POST['key']) ? (string) $_POST['key'] : '');
    $markdown = isset($_POST['markdown']) ? (string) $_POST['markdown'] : '';
    cms_utf8_or_fail($key, $markdown);
    $path = cms_region_path($key, false);
    if (!$path) { cms_fail('Nieprawidłowy identyfikator fragmentu.'); }
    if (is_file($path)) { cms_fail('Ten plik juz istnieje.', 409); }
    if ($markdown === '') {
        $markdown = "# " . str_replace('-', ' ', basename($key)) . "\n\nNew content.\n";
    }
    if (!cms_atomic_write($path, str_replace("\r\n", "\n", $markdown))) {
        cms_fail('Nie udało się utworzyć pliku Markdown.', 500);
    }
    cms_regenerate_indexes();
    cms_json(array('ok' => true, 'key' => $key, 'inventory' => cms_content_inventory()));

case 'save-media-meta':
    $rel = isset($_POST['rel']) ? (string) $_POST['rel'] : '';
    $alt = trim(isset($_POST['alt']) ? (string) $_POST['alt'] : '');
    $caption = trim(isset($_POST['caption']) ? (string) $_POST['caption'] : '');
    cms_utf8_or_fail($rel, $alt, $caption);
    $path = cms_media_path($rel, true);
    if (!$path) { cms_fail('Nie znaleziono pliku.', 404); }
    if (!cms_media_write_meta($path, array('alt' => $alt, 'caption' => $caption))) {
        cms_fail('Nie udało się zapisać metadanych pliku.', 500);
    }
    cms_json(array('ok' => true, 'asset' => cms_media_asset($rel)));

case 'delete-media':
    $rel = isset($_POST['rel']) ? (string) $_POST['rel'] : '';
    cms_utf8_or_fail($rel);
    $asset = cms_media_asset($rel);
    if (!$asset) { cms_fail('Nie znaleziono pliku.', 404); }
    if (cms_media_is_referenced($asset['url'])) {
        cms_fail('Plik jest nadal używany w treści. Usuń odniesienia przed skasowaniem.', 409);
    }
    $path = cms_media_path($rel, true);
    if (!$path || !@unlink($path)) { cms_fail('Nie udało się skasować pliku.', 500); }
    @unlink(cms_media_meta_path($path));
    cms_json(array('ok' => true));

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
    $rel = $sub . '/' . $name;
    cms_media_write_meta($dir . '/' . $name, array('alt' => $base, 'caption' => ''));
    $asset = cms_media_asset($rel);
    if (!$asset) { cms_fail('Nie udało się odczytać zapisanego pliku.', 500); }
    cms_json(array(
        'ok' => true,
        'url' => $asset['url'],
        'kind' => $asset['kind'],
        'markdown' => $asset['markdown'],
        'asset' => $asset,
    ));

case 'logout':
    cms_logout();
    header('Location: /');
    exit;

default:
    cms_fail('Nieznana akcja.', 400);
}
