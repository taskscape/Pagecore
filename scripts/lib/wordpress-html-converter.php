<?php

final class PagecoreWordPressHtmlConverter {
    private $doc;
    private $uploadsUrl;
    private $diagnostics = array();

    public function __construct($uploadsUrl) { $this->uploadsUrl = rtrim((string) $uploadsUrl, '/'); }
    /** Convert an HTML fragment to Markdown text. */
    public function convert($html) {
        $this->diagnostics = array();
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

    public function diagnostics() { return $this->diagnostics; }

    private function stripGutenberg($html) {
        // drop <!-- wp:xxx --> and <!-- /wp:xxx --> wrappers; keep inner HTML
        return preg_replace('~<!--\s*/?wp:[^>]*?-->~s', '', $html);
    }

    private function normalize($s) {
        $s = preg_replace("~[ \t]+\n~", "\n", $s);
        $s = preg_replace("~\n{3,}~", "\n\n", $s);
        return trim($s) . "\n";
    }

    private static $ACTIVE_TAGS = array('iframe','script','object','embed','video','audio','source','svg','form',
        'input','button','textarea','select','option','style','template','math','noscript');
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
            if (in_array($tag, self::$ACTIVE_TAGS, true)) {
                $flush();
                $replacement = $this->activeElement($child, $tag);
                if ($replacement !== '') { $out[] = $replacement; }
                continue;
            }
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
        // image (or safe embed link) + optional caption
        $parts = array();
        foreach ($node->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE) {
                $t = strtolower($c->nodeName);
                if ($t === 'figcaption') {
                    $cap = trim($this->collapse($this->inline($c)));
                    if ($cap !== '') { $parts[] = '*' . $cap . '*'; }
                    continue;
                }
                if (in_array($t, self::$ACTIVE_TAGS, true)) {
                    $replacement = $this->activeElement($c, $t);
                    if ($replacement !== '') { $parts[] = $replacement; }
                    continue;
                }
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
        if (in_array($tag, self::$ACTIVE_TAGS, true)) { return $this->activeElement($node, $tag); }
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
        $href = $this->safeUrl($this->rewriteUploads($node->getAttribute('href')), true);
                $t = trim($inner);
                if ($t === '') { $t = $href; }
                return $href === '' ? $t : '[' . $t . '](' . $this->markdownUrl($href) . ')';
            case 'img':
                return $this->image($node);
            case 'figure': case 'p': case 'div': case 'span': case 'section':
                return $inner; // unwrap inline-ish
            default:
                return $inner;
        }
    }

    private function image($node) {
        $src = $this->safeUrl($this->rewriteUploads($node->getAttribute('src')), false);
        $alt = $node->getAttribute('alt');
        if ($alt === '') { $alt = $node->getAttribute('title'); }
        $alt = trim(preg_replace('~\s+~', ' ', $alt));
        return $src === '' ? $alt : '![' . $alt . '](' . $this->markdownUrl($src) . ')';
    }

    private function activeElement($node, $tag) {
        $url = '';
        $label = 'Open embedded content';
        if ($tag === 'script') {
            $url = $this->safeUrl($node->getAttribute('data-publication'), false);
            $label = 'Open embedded publication';
            if ($url === '' && preg_match('~calLink\s*:\s*["\']([A-Za-z0-9/_-]+)["\']~', $node->textContent, $match)) {
                $url = 'https://cal.com/' . ltrim($match[1], '/');
                $label = 'Book a meeting';
            }
        } elseif ($tag === 'object') {
            $url = $this->safeUrl($this->rewriteUploads($node->getAttribute('data')), false);
            $label = 'Open document';
        } elseif (in_array($tag, array('iframe','video','audio','source'), true)) {
            $url = $this->safeUrl($this->rewriteUploads($node->getAttribute('src')), false);
            $label = $tag === 'iframe' ? 'Open embedded content' : 'Open media';
        }
        $this->diagnostics[] = 'active:' . $tag . ':' . ($url === '' ? 'dropped' : 'linked');
        return $url === '' ? '' : '[' . $label . '](' . $this->markdownUrl($url) . ')';
    }

    private function safeUrl($url, $allowMailto) {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || strpos($url, "\\") !== false || preg_match('~[\x00-\x1F\x7F]~', $url)) { return ''; }
        if ($url[0] === '/') { return strncmp($url, '//', 2) === 0 ? '' : $url; }
        if ($url[0] === '#') { return $url; }
        $parts = parse_url($url);
        if ($parts === false) { return ''; }
        if (!isset($parts['scheme'])) {
            return strpos($url, ':') === false ? $url : '';
        }
        $scheme = strtolower($parts['scheme']);
        if (($scheme === 'http' || $scheme === 'https') && isset($parts['host'])) { return $url; }
        if ($allowMailto && $scheme === 'mailto' && filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL)) { return $url; }
        return '';
    }

    private function markdownUrl($url) {
        return str_replace(array(' ', '(', ')'), array('%20', '%28', '%29'), $url);
    }

    private function rewriteUploads($text) {
        return preg_replace(
            '~(?:https?://[^\s"\'<>()]*?)?/(?:[a-z0-9_-]+/)?wp-content/uploads/~i',
            $this->uploadsUrl . '/',
            (string) $text
        );
    }

    private function collapse($s) {
        // collapse runs of spaces/tabs but keep newlines
        return preg_replace("~[ \t]+~", ' ', $s);
    }
}
