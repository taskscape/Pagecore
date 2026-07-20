<?php
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
 * Anything it does not recognise (iframes, Twitter/Instagram embeds, <script>,
 * <video>…) is preserved verbatim as raw HTML — Pagecore renders post bodies
 * with allow_html=true, so embeds keep working without a lossy conversion.
 *
 * Usage (see --help):
 *   php scripts/import-wordpress.php \
 *     --sql=C:/Install/zagozda.eu/mojerzec_zagozdaeu_1784400609.sql \
 *     --uploads-src=C:/Install/zagozda.eu/private_html/wp-content/uploads \
 *     --out-content=C:/Projects/Pagecore/zagozda/content \
 *     --out-uploads=C:/Projects/Pagecore/zagozda/uploads \
 *     --table-prefix=zagozda_ \
 *     --status=publish,private \
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
    'post-url' => '/post/{slug}/',
    'uploads-url' => '/uploads',
    'copy-uploads' => '1',
    'help' => '0',
);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') { $opt['help'] = '1'; continue; }
    if (preg_match('~^--([a-z-]+)=(.*)$~s', $arg, $m)) { $opt[$m[1]] = $m[2]; }
}
if ($opt['help'] === '1' || $opt['sql'] === '') {
    fwrite(STDOUT, "WordPress -> Pagecore importer\n\n"
        . "Required:\n"
        . "  --sql=PATH             mysqldump .sql file\n"
        . "  --out-content=DIR      target content/ dir (posts/ and pages/ written here)\n"
        . "Optional:\n"
        . "  --uploads-src=DIR      WordPress wp-content/uploads dir (to copy media)\n"
        . "  --out-uploads=DIR      target uploads/ dir\n"
        . "  --table-prefix=STR     DB table prefix (default wp_)\n"
        . "  --status=LIST          post statuses to import (default publish)\n"
        . "  --post-url=PATTERN     post URL pattern with {slug} (default /post/{slug}/)\n"
        . "  --uploads-url=PATH     public uploads base (default /uploads)\n"
        . "  --copy-uploads=0|1     copy referenced media files (default 1)\n");
    exit($opt['sql'] === '' ? 1 : 0);
}

$PREFIX = $opt['table-prefix'];
$STATUSES = array_filter(array_map('trim', explode(',', $opt['status'])));
$OUT_CONTENT = rtrim(str_replace('\\', '/', $opt['out-content']), '/');
$OUT_UPLOADS = rtrim(str_replace('\\', '/', $opt['out-uploads']), '/');
$UPLOADS_SRC = rtrim(str_replace('\\', '/', $opt['uploads-src']), '/');
$UPLOADS_URL = rtrim($opt['uploads-url'], '/');
$POST_URL = $opt['post-url'];
$COPY = $opt['copy-uploads'] === '1' && $UPLOADS_SRC !== '' && $OUT_UPLOADS !== '';

if ($OUT_CONTENT === '') { fwrite(STDERR, "--out-content is required\n"); exit(1); }
if (!is_file($opt['sql'])) { fwrite(STDERR, "SQL file not found: {$opt['sql']}\n"); exit(1); }
if (strpos($POST_URL, '{slug}') === false) {
    fwrite(STDERR, "--post-url must contain the literal {slug} placeholder; quote this argument in PowerShell.\n");
    exit(1);
}

function say($s) { fwrite(STDOUT, $s . "\n"); }
function ensure_dir($d) { if (!is_dir($d) && !@mkdir($d, 0775, true)) { fwrite(STDERR, "mkdir failed: $d\n"); exit(1); } }

/* --------------------------------------------------------- SQL value parser */
/** Extract every row-tuple for a table from all its INSERT statements. */
function sql_rows($sql, $table) {
    $rows = array();
    $needle = "INSERT INTO `$table` VALUES ";
    $len = strlen($sql);
    $from = 0;
    while (($pos = strpos($sql, $needle, $from)) !== false) {
        $i = $pos + strlen($needle);
        // parse tuples until the terminating ";\n"
        $depth = 0; $cur = ''; $inq = false; $esc = false;
        for (; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($esc) { $cur .= $ch; $esc = false; continue; }
            if ($ch === '\\') { $cur .= $ch; $esc = true; continue; }
            if ($ch === "'") { $inq = !$inq; $cur .= $ch; continue; }
            if (!$inq) {
                if ($ch === '(') { if ($depth === 0) { $cur = ''; } else { $cur .= $ch; } $depth++; continue; }
                if ($ch === ')') { $depth--; if ($depth === 0) { $rows[] = $cur; $cur = ''; } else { $cur .= $ch; } continue; }
                if ($ch === ';' && $depth === 0) { break; }
            }
            $cur .= $ch;
        }
        $from = $i;
    }
    return $rows;
}

/** Split one row tuple into raw field strings (still quoted/escaped). */
function sql_fields($row) {
    $f = array(); $cur = ''; $inq = false; $esc = false;
    $len = strlen($row);
    for ($i = 0; $i < $len; $i++) {
        $ch = $row[$i];
        if ($esc) { $cur .= $ch; $esc = false; continue; }
        if ($ch === '\\') { $cur .= $ch; $esc = true; continue; }
        if ($ch === "'") { $inq = !$inq; $cur .= $ch; continue; }
        if ($ch === ',' && !$inq) { $f[] = $cur; $cur = ''; continue; }
        $cur .= $ch;
    }
    $f[] = $cur;
    return $f;
}

/** Unquote + unescape a single SQL field to its PHP string/NULL. */
function sql_val($s) {
    $s = trim($s);
    if ($s === 'NULL') { return null; }
    if (strlen($s) >= 2 && $s[0] === "'" && substr($s, -1) === "'") {
        $s = substr($s, 1, -1);
    }
    return strtr($s, array(
        "\\'" => "'", '\\"' => '"', '\\n' => "\n", '\\r' => "\r",
        '\\t' => "\t", '\\0' => "\0", '\\Z' => "\x1a", '\\\\' => '\\',
    ));
}

/* ------------------------------------------------------------- URL rewrite */
$GLOBALS['REWRITE_UPLOADS_URL'] = $UPLOADS_URL;
/** Rewrite any WordPress uploads URL (absolute, /blog-prefixed, or relative) to the Pagecore uploads path. */
function rewrite_uploads($text) {
    return preg_replace(
        '~(?:https?://[^\s"\'<>()]*?)?/(?:[a-z0-9_-]+/)?wp-content/uploads/~i',
        $GLOBALS['REWRITE_UPLOADS_URL'] . '/',
        (string) $text
    );
}

/* ------------------------------------------------------ HTML -> Markdown */
class Html2Md {
    private $doc;
    /** Convert an HTML fragment to Markdown text. */
    public function convert($html) {
        $html = $this->stripGutenberg($html);
        $html = str_replace(array("\r\n", "\r"), "\n", $html);
        if (trim($html) === '') { return ''; }
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // force UTF-8 without mbstring; NOIMPLIED/NODEFDTD keep our wrapper clean
        $this->doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="pc-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        $root = $this->doc->getElementById('pc-root');
        if (!$root) { return $this->normalize(strip_tags($html)); }
        return $this->normalize($this->block($root));
    }

    private function stripGutenberg($html) {
        // drop <!-- wp:xxx --> and <!-- /wp:xxx --> wrappers; keep inner HTML
        return preg_replace('~<!--\s*/?wp:[^>]*?-->~s', '', $html);
    }

    private function normalize($s) {
        $s = preg_replace("~[ \t]+\n~", "\n", $s);
        $s = preg_replace("~\n{3,}~", "\n\n", $s);
        return trim($s) . "\n";
    }

    private function raw($node) {
        return $this->doc->saveHTML($node);
    }

    private static $RAWTAGS = array('iframe','script','object','embed','video','audio','source','svg','form','noscript');
    private static $BLOCK = array('p','h1','h2','h3','h4','h5','h6','ul','ol','blockquote','figure',
        'table','pre','hr','div','section','article','header','footer','aside','main','figcaption');

    /** Render block-level children, paragraphs separated by blank lines. */
    private function block($node) {
        $out = array();
        $inline = '';
        $flush = function () use (&$inline, &$out) {
            $t = trim($this->collapse($inline));
            if ($t !== '') { $out[] = $t; }
            $inline = '';
        };
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                // blank line in source text = paragraph break
                $parts = preg_split('~\n[ \t]*\n~', $child->nodeValue);
                for ($i = 0; $i < count($parts); $i++) {
                    $inline .= $parts[$i];
                    if ($i < count($parts) - 1) { $flush(); }
                }
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) { continue; }
            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::$RAWTAGS, true)) { $flush(); $out[] = trim($this->raw($child)); continue; }
            if (in_array($tag, self::$BLOCK, true)) { $flush(); $b = $this->blockElement($child, $tag); if (trim($b) !== '') { $out[] = trim($b); } continue; }
            // inline element -> accumulate
            $inline .= $this->inline($child);
        }
        $flush();
        return implode("\n\n", $out);
    }

    private function blockElement($node, $tag) {
        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $level = (int) substr($tag, 1);
                return str_repeat('#', $level) . ' ' . trim($this->collapse($this->inline($node)));
            case 'p':
                return trim($this->collapse($this->inline($node)));
            case 'hr':
                return '---';
            case 'br':
                return '';
            case 'ul': case 'ol':
                return $this->list($node, $tag);
            case 'blockquote':
                $cls = strtolower($node->getAttribute('class'));
                if (strpos($cls, 'twitter') !== false || strpos($cls, 'instagram') !== false || strpos($cls, 'tiktok') !== false) {
                    return trim($this->raw($node));
                }
                $inner = $this->block($node);
                $lines = explode("\n", $inner);
                foreach ($lines as &$l) { $l = ($l === '') ? '>' : '> ' . $l; }
                return implode("\n", $lines);
            case 'pre':
                $code = $node->textContent;
                return "```\n" . rtrim($code, "\n") . "\n```";
            case 'table':
                return $this->table($node);
            case 'figure':
                return $this->figure($node);
            case 'figcaption':
                $t = trim($this->collapse($this->inline($node)));
                return $t === '' ? '' : '*' . $t . '*';
            default: // div/section/article/etc -> unwrap
                return $this->block($node);
        }
    }

    private function figure($node) {
        // image (or raw embed) + optional caption
        $parts = array();
        foreach ($node->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE) {
                $t = strtolower($c->nodeName);
                if ($t === 'figcaption') {
                    $cap = trim($this->collapse($this->inline($c)));
                    if ($cap !== '') { $parts[] = '*' . $cap . '*'; }
                    continue;
                }
                if (in_array($t, self::$RAWTAGS, true)) { $parts[] = trim($this->raw($c)); continue; }
            }
            $b = trim($this->collapse($this->inline($c)));
            if ($b !== '') { $parts[] = $b; }
        }
        return implode("\n\n", array_filter($parts, function ($x) { return trim($x) !== ''; }));
    }

    private function list($node, $tag) {
        $lines = array(); $n = 1;
        foreach ($node->childNodes as $li) {
            if ($li->nodeType !== XML_ELEMENT_NODE || strtolower($li->nodeName) !== 'li') { continue; }
            $marker = $tag === 'ol' ? ($n++ . '. ') : '- ';
            $text = trim($this->collapse($this->inline($li)));
            $text = str_replace("\n", ' ', $text);
            $lines[] = $marker . $text;
        }
        return implode("\n", $lines);
    }

    private function table($node) {
        $rows = array();
        foreach ($node->getElementsByTagName('tr') as $tr) {
            $cells = array();
            foreach ($tr->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), array('td','th'), true)) {
                    $cells[] = trim(str_replace('|', '\\|', $this->collapse($this->inline($cell))));
                }
            }
            if ($cells) { $rows[] = $cells; }
        }
        if (!$rows) { return ''; }
        $cols = count($rows[0]);
        $out = '| ' . implode(' | ', $rows[0]) . ' |';
        $out .= "\n| " . implode(' | ', array_fill(0, $cols, '---')) . ' |';
        for ($i = 1; $i < count($rows); $i++) {
            $r = array_pad($rows[$i], $cols, '');
            $out .= "\n| " . implode(' | ', array_slice($r, 0, $cols)) . ' |';
        }
        return $out;
    }

    /** Inline rendering -> Markdown inline syntax. */
    private function inline($node) {
        if ($node->nodeType === XML_TEXT_NODE) { return $node->nodeValue; }
        if ($node->nodeType !== XML_ELEMENT_NODE) { return ''; }
        $tag = strtolower($node->nodeName);
        if (in_array($tag, self::$RAWTAGS, true)) { return $this->raw($node); }
        $inner = '';
        foreach ($node->childNodes as $c) { $inner .= $this->inline($c); }
        switch ($tag) {
            case 'strong': case 'b':
                return ($t = trim($inner)) === '' ? '' : '**' . $t . '**';
            case 'em': case 'i':
                return ($t = trim($inner)) === '' ? '' : '*' . $t . '*';
            case 'code':
                return '`' . $inner . '`';
            case 'br':
                return "\n";
            case 'a':
                $href = rewrite_uploads($node->getAttribute('href'));
                $t = trim($inner);
                if ($t === '') { $t = $href; }
                return '[' . $t . '](' . $href . ')';
            case 'img':
                return $this->image($node);
            case 'figure': case 'p': case 'div': case 'span': case 'section':
                return $inner; // unwrap inline-ish
            default:
                return $inner;
        }
    }

    private function image($node) {
        $src = rewrite_uploads($node->getAttribute('src'));
        $alt = $node->getAttribute('alt');
        if ($alt === '') { $alt = $node->getAttribute('title'); }
        $alt = trim(preg_replace('~\s+~', ' ', $alt));
        return '![' . $alt . '](' . $src . ')';
    }

    private function collapse($s) {
        // collapse runs of spaces/tabs but keep newlines
        return preg_replace("~[ \t]+~", ' ', $s);
    }
}

/* ------------------------------------------------------------------- load */
say('Reading SQL: ' . $opt['sql']);
$sql = file_get_contents($opt['sql']);
say('  ' . number_format(strlen($sql)) . ' bytes');

$postRows = sql_rows($sql, $PREFIX . 'posts');
$metaRows = sql_rows($sql, $PREFIX . 'postmeta');
$relRows  = sql_rows($sql, $PREFIX . 'term_relationships');
$ttRows   = sql_rows($sql, $PREFIX . 'term_taxonomy');
$termRows = sql_rows($sql, $PREFIX . 'terms');
$optionRows = sql_rows($sql, $PREFIX . 'options');
$primaryRows = sql_rows($sql, $PREFIX . 'yoast_primary_term');
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
    if (!is_string($value) || $value === '') { return null; }
    $decoded = @unserialize($value, array('allowed_classes' => false));
    return $decoded === false && $value !== 'b:0;' ? null : $decoded;
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

/** Replace same-site WordPress absolute links with root-relative Pagecore URLs. */
function menu_internal_url($url, array $siteUrls) {
    $url = trim((string) $url);
    if ($url === '') { return '#'; }
    if ($url[0] === '/') { return preg_replace('~^/blog(?=/|$)~', '', $url); }
    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) { return $url; }
    $host = strtolower($parts['host']);
    foreach ($siteUrls as $siteUrl) {
        $site = @parse_url($siteUrl);
        if (!is_array($site) || empty($site['host']) || strtolower($site['host']) !== $host) { continue; }
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $path = preg_replace('~^/blog(?=/|$)~', '', $path);
        if ($path === '') { $path = '/'; }
        if (!empty($parts['query'])) { $path .= '?' . $parts['query']; }
        if (!empty($parts['fragment'])) { $path .= '#' . $parts['fragment']; }
        return $path;
    }
    return $url;
}

/** Published page/post records used to resolve WordPress menu object links. */
function menu_content_objects(array $postRows, $postUrl) {
    $objects = array();
    foreach ($postRows as $r) {
        $f = sql_fields($r);
        if (count($f) < 21) { continue; }
        $type = sql_val($f[20]);
        if ($type !== 'post' && $type !== 'page') { continue; }
        $id = (string) sql_val($f[0]);
        $slug = trim((string) sql_val($f[11]));
        if ($slug === '') { continue; }
        $objects[$id] = array(
            'title' => (string) sql_val($f[5]),
            'url' => $type === 'post' ? str_replace('{slug}', $slug, $postUrl) : '/' . $slug . '/',
        );
    }
    return $objects;
}

/** Find the nav-menu term assigned to the active theme's primary location. */
function primary_menu_term_id(array $options) {
    $stylesheet = isset($options['stylesheet']) ? trim((string) $options['stylesheet']) : '';
    $keys = array();
    if ($stylesheet !== '') { $keys[] = 'theme_mods_' . $stylesheet; }
    foreach ($options as $key => $ignore) {
        if (strpos($key, 'theme_mods_') === 0 && !in_array($key, $keys, true)) { $keys[] = $key; }
    }
    foreach ($keys as $key) {
        if (!isset($options[$key])) { continue; }
        $mods = wp_unserialize_option($options[$key]);
        if (!is_array($mods) || empty($mods['nav_menu_locations']) || !is_array($mods['nav_menu_locations'])) { continue; }
        $locations = $mods['nav_menu_locations'];
        if (!empty($locations['primary'])) { return (string) $locations['primary']; }
        foreach ($locations as $termId) { if ((int) $termId > 0) { return (string) $termId; } }
    }
    return '';
}

/** Convert the selected WordPress nav_menu into Pagecore's nested nav.json shape. */
function imported_nav_items(array $postRows, array $meta, array $rel, array $tt, array $terms,
                            array $options, $postUrl, &$menuLabel = '') {
    $menuPosts = array();
    foreach ($postRows as $r) {
        $f = sql_fields($r);
        if (count($f) < 21 || sql_val($f[20]) !== 'nav_menu_item' || sql_val($f[7]) !== 'publish') { continue; }
        $id = (string) sql_val($f[0]);
        $menuTermIds = array();
        foreach (isset($rel[$id]) ? $rel[$id] : array() as $ttid) {
            if (isset($tt[$ttid]) && $tt[$ttid][1] === 'nav_menu') { $menuTermIds[] = (string) $tt[$ttid][0]; }
        }
        if (!$menuTermIds) { continue; }
        $menuPosts[$id] = array(
            'id' => $id,
            'title' => (string) sql_val($f[5]),
            'order' => (int) sql_val($f[19]),
            'menu_terms' => $menuTermIds,
        );
    }
    if (!$menuPosts) { return array(); }

    $selected = primary_menu_term_id($options);
    if ($selected === '') {
        $counts = array();
        foreach ($menuPosts as $item) {
            foreach ($item['menu_terms'] as $termId) { $counts[$termId] = isset($counts[$termId]) ? $counts[$termId] + 1 : 1; }
        }
        arsort($counts);
        $selected = (string) key($counts);
    }
    $menuLabel = isset($terms[$selected]) ? $terms[$selected][0] : $selected;

    $objects = menu_content_objects($postRows, $postUrl);
    $siteUrls = array();
    foreach (array('home', 'siteurl') as $key) { if (!empty($options[$key])) { $siteUrls[] = $options[$key]; } }
    $items = array();
    foreach ($menuPosts as $id => $row) {
        if (!in_array($selected, $row['menu_terms'], true)) { continue; }
        $m = isset($meta[$id]) ? $meta[$id] : array();
        $parent = isset($m['_menu_item_menu_item_parent']) ? (string) $m['_menu_item_menu_item_parent'] : '0';
        $objectId = isset($m['_menu_item_object_id']) ? (string) $m['_menu_item_object_id'] : '';
        $object = isset($m['_menu_item_object']) ? (string) $m['_menu_item_object'] : '';
        $type = isset($m['_menu_item_type']) ? (string) $m['_menu_item_type'] : '';
        $label = trim($row['title']);
        $url = '';

        if ($type === 'custom') {
            $url = isset($m['_menu_item_url']) ? menu_internal_url($m['_menu_item_url'], $siteUrls) : '#';
        } elseif ($type === 'post_type' && isset($objects[$objectId])) {
            $url = $objects[$objectId]['url'];
            if ($label === '') { $label = $objects[$objectId]['title']; }
        } elseif ($type === 'taxonomy' && isset($terms[$objectId])) {
            $term = $terms[$objectId];
            $url = $object === 'post_tag' ? '/tag/' . $term[1] . '/' : '/kategoria/' . $term[1] . '/';
            if ($label === '') { $label = $term[0]; }
        }
        if ($url === '' && isset($m['_menu_item_url'])) { $url = menu_internal_url($m['_menu_item_url'], $siteUrls); }
        if ($label === '') { $label = $object !== '' ? $object : 'Menu'; }
        if ($url === '') { $url = '#'; }
        $items[$id] = array('id' => $id, 'parent' => $parent, 'order' => $row['order'],
            'label' => $label, 'url' => $url, 'children' => array());
    }
    if (!$items) { return array(); }

    uasort($items, function ($a, $b) {
        if ($a['order'] !== $b['order']) { return $a['order'] - $b['order']; }
        return (int) $a['id'] - (int) $b['id'];
    });
    $childrenByParent = array();
    foreach ($items as $id => $item) {
        $parent = $item['parent'];
        if ($parent === $id || !isset($items[$parent])) { $parent = '0'; }
        $childrenByParent[$parent][] = $id;
    }
    $build = function ($parent, array $trail = array()) use (&$build, &$items, &$childrenByParent) {
        $out = array();
        foreach (isset($childrenByParent[$parent]) ? $childrenByParent[$parent] : array() as $id) {
            if (isset($trail[$id])) { continue; }
            $nextTrail = $trail; $nextTrail[$id] = true;
            $item = $items[$id];
            $item['children'] = $build($id, $nextTrail);
            unset($item['id'], $item['parent'], $item['order']);
            $out[] = $item;
        }
        return $out;
    };
    return $build('0');
}

/* ------------------------------------------------------------- conversion */
ensure_dir($OUT_CONTENT . '/posts');
ensure_dir($OUT_CONTENT . '/pages');

$conv = new Html2Md();
$statusSet = array_flip($STATUSES);
$referencedUploads = array(); // uploads-relative path => true
$categoriesUsed = array();    // slug => name
$pagesForSearch = array();    // url => [title, 'Page', region]
$counts = array('post' => 0, 'page' => 0, 'skipped_status' => 0, 'skipped_empty_slug' => 0);
$slugSeen = array();

function unique_slug($slug, &$seen) {
    if ($slug === '') { $slug = 'wpis'; }
    $base = $slug; $n = 2;
    while (isset($seen[$slug])) { $slug = $base . '-' . $n; $n++; }
    $seen[$slug] = true;
    return $slug;
}

function fm_escape($v) { return str_replace(array("\r", "\n"), ' ', (string) $v); }

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

    $slug = $name !== '' ? $name : preg_replace('~[^a-z0-9]+~', '-', strtolower($title));
    $slug = trim(preg_replace('~[^a-z0-9-]+~', '-', $slug), '-');

    // rewrite upload URLs across raw content first, then convert
    $content = rewrite_uploads($content);
    $bodyMd = $conv->convert($content);

    // collect referenced uploads from the converted markdown
    if (preg_match_all('~' . preg_quote($UPLOADS_URL, '~') . '/([^\s"\'<>()\]]+)~', $bodyMd, $mm)) {
        foreach ($mm[1] as $rp) { $referencedUploads[rawurldecode($rp)] = true; }
    }

    // featured image
    $image = '';
    if (isset($meta[$id]['_thumbnail_id'])) {
        $tid = $meta[$id]['_thumbnail_id'];
        if (isset($attachPath[$tid])) {
            $image = $UPLOADS_URL . '/' . $attachPath[$tid];
            $referencedUploads[$attachPath[$tid]] = true;
        }
    }

    if ($type === 'page') {
        $slug = unique_slug($slug, $slugSeen);
        $regionDir = $OUT_CONTENT . '/pages/' . $slug;
        ensure_dir($regionDir);
        file_put_contents($regionDir . '/body.md', $bodyMd);
        $pagesForSearch['/' . $slug . '/'] = array($title !== '' ? $title : $slug, 'Page', $slug . '/body');
        $counts['page']++;
        continue;
    }

    // post: pick category (primary first, skip 'uncategorized' when a real one exists)
    $cats = post_categories($id, $rel, $tt, $terms, $primaryCat);
    $catSlug = '';
    foreach ($cats as $c) {
        list($cname, $cslug) = $c;
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

    file_put_contents($OUT_CONTENT . '/posts/' . $slug . '.md', $fm . $bodyMd);
    $counts['post']++;
    if ($counts['post'] % 250 === 0) { say('  ...' . $counts['post'] . ' posts'); }
}

say(sprintf('Wrote %d posts (%d tagged), %d pages (skipped %d by status).',
    $counts['post'], isset($counts['tagged']) ? $counts['tagged'] : 0, $counts['page'], $counts['skipped_status']));

$menuLabel = '';
$navItems = imported_nav_items($postRows, $meta, $rel, $tt, $terms, $options, $POST_URL, $menuLabel);
if ($navItems) {
    $navJson = json_encode($navItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($navJson === false) {
        fwrite(STDERR, "Could not encode imported navigation as JSON\n");
        exit(1);
    }
    file_put_contents($OUT_CONTENT . '/nav.json', $navJson . "\n");
    say(sprintf('Wrote navigation: %s (%d top-level items)', $menuLabel, count($navItems)));
} else {
    say('No published WordPress navigation menu found; nav.json not written.');
}

/* --------------------------------------------------------- copy uploads */
if ($COPY) {
    say('Copying ' . count($referencedUploads) . ' referenced upload files...');
    $copied = 0; $missing = 0;
    foreach (array_keys($referencedUploads) as $rp) {
        $src = $UPLOADS_SRC . '/' . $rp;
        if (!is_file($src)) { $missing++; continue; }
        $dst = $OUT_UPLOADS . '/' . $rp;
        ensure_dir(dirname($dst));
        if (@copy($src, $dst)) { $copied++; }
    }
    say("  copied $copied, missing $missing");
} else {
    say('Skipping upload copy (referenced files: ' . count($referencedUploads) . ')');
}

/* ------------------------------------------------ config fragment output */
// order categories by usage count is not tracked here; emit alphabetically-stable
ksort($categoriesUsed);
$catPhp = "    'categories' => array(\n";
foreach ($categoriesUsed as $slug => $name) {
    $catPhp .= sprintf("        %s => array(%s, '/kategoria/%s/'),\n",
        var_export($slug, true), var_export($name, true), $slug);
}
$catPhp .= "    ),\n";

$searchPhp = "    'search_pages' => array(\n";
$searchPhp .= "        '/' => array('Strona główna', 'Listing', null),\n";
foreach ($pagesForSearch as $url => $def) {
    $searchPhp .= sprintf("        %s => array(%s, 'Page', %s),\n",
        var_export($url, true), var_export($def[0], true), var_export($def[2], true));
}
$searchPhp .= "    ),\n";

$fragment = "<?php\n// Generated by import-wordpress.php on " . date('c') . "\n"
    . "// Merge these keys into cms/config.php.\n"
    . "return array(\n" . $catPhp . $searchPhp
    . "    'post_url' => " . var_export($POST_URL, true) . ",\n"
    . ");\n";
file_put_contents($OUT_CONTENT . '/imported-config-fragment.php', $fragment);
say('Wrote config fragment: ' . $OUT_CONTENT . '/imported-config-fragment.php');
say('Categories used: ' . count($categoriesUsed) . ' | Pages: ' . count($pagesForSearch));
say('Done.');
