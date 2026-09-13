<?php

/**
 * Expand WordPress page-builder shortcodes into plain HTML.
 *
 * WPBakery (`vc_*`) and Qode theme (`mindcare_core_*`) store page content as
 * nested shortcodes rather than HTML, so the HTML -> Markdown converter alone
 * would emit the builder markup verbatim. This pass runs first and reduces a
 * builder tree to ordinary HTML: layout wrappers are unwrapped, editorial
 * shortcodes become headings/images/links, and shortcodes that only make sense
 * against a live WordPress install (sliders, product grids, booking calendars)
 * are dropped. Everything it emits still passes through the HTML converter's
 * allowlist, so this never widens the safe-Markdown boundary.
 */
final class PagecoreWordPressShortcodes {
    private $uploadsUrl;
    private $attachments;
    private $diagnostics = array();

    /** Layout-only shortcodes: keep their content, discard the wrapper. */
    private static $unwrap = array(
        'vc_row' => 1, 'vc_column' => 1, 'vc_row_inner' => 1, 'vc_column_inner' => 1,
        'vc_section' => 1, 'vc_column_text' => 1, 'vc_wp_text' => 1, 'vc_accordion' => 1,
        'vc_tta_section' => 1, 'vc_tta_accordion' => 1, 'vc_tta_tabs' => 1, 'vc_tabs' => 1,
        'mindcare_core_grid_element' => 1, 'mindcare_core_accordion' => 1,
        'mindcare_core_elements_holder' => 1, 'mindcare_core_elements_holder_item' => 1,
    );

    /** Shortcodes that require a live WordPress runtime; nothing to migrate. */
    private static $drop = array(
        'vc_empty_space' => 1, 'rev_slider' => 1, 'contact-form-7' => 1, 'mailpoet_page' => 1,
        'woocommerce_cart' => 1, 'woocommerce_checkout' => 1, 'woocommerce_my_account' => 1,
        'mindcare_core_blog_list' => 1, 'mindcare_core_product_list' => 1,
        'mindcare_core_team_list' => 1, 'mindcare_core_clients_list' => 1,
        'mindcare_core_testimonials_list' => 1, 'mindcare_core_booked_calendar' => 1,
        'mindcare_core_progress_bar' => 1, 'mindcare_core_counter' => 1,
    );

    public function __construct($uploadsUrl, array $attachments = array()) {
        $this->uploadsUrl = rtrim((string) $uploadsUrl, '/');
        $this->attachments = $attachments;
    }

    public function toHtml($content) {
        $this->diagnostics = array();
        return $this->renderChildren($this->parse((string) $content));
    }

    public function diagnostics() { $names = array_keys($this->diagnostics); sort($names); return $names; }

    /* ------------------------------------------------------------- parsing */

    /**
     * Build a shortcode tree. A name is only treated as a shortcode when it
     * looks like builder markup, so editorial text such as "[ADRES]" or a bare
     * "[20px]" survives as literal content.
     */
    private function parse($text) {
        preg_match_all('~\[/([a-zA-Z0-9_-]+)\]~', $text, $closes);
        $paired = array_flip($closes[1]);
        $parts = preg_split('~(\[/?[a-zA-Z0-9_-]+(?:[^\]]*)?\])~s', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) { return array(array('text' => $text)); }
        $stack = array(array('name' => '', 'attrs' => array(), 'children' => array()));
        foreach ($parts as $index => $part) {
            $top = count($stack) - 1;
            if ($index % 2 === 0) {
                if ($part !== '') { $stack[$top]['children'][] = array('text' => $part); }
                continue;
            }
            if (preg_match('~^\[/([a-zA-Z0-9_-]+)\]$~', $part, $close)) {
                $name = strtolower($close[1]);
                $depth = null;
                for ($i = $top; $i > 0; $i--) { if ($stack[$i]['name'] === $name) { $depth = $i; break; } }
                if ($depth === null) { $stack[$top]['children'][] = array('text' => $part); continue; }
                while (count($stack) - 1 >= $depth) {
                    $node = array_pop($stack);
                    $stack[count($stack) - 1]['children'][] = $node;
                }
                continue;
            }
            if (!preg_match('~^\[([a-zA-Z0-9_-]+)((?:[^\]]*)?)\]$~s', $part, $open)) {
                $stack[$top]['children'][] = array('text' => $part);
                continue;
            }
            $name = strtolower($open[1]);
            $rawAttributes = $open[2];
            if (!$this->isShortcodeName($name) && trim($rawAttributes) === '' && !isset($paired[$name])) {
                $stack[$top]['children'][] = array('text' => $part);
                continue;
            }
            $node = array('name' => $name, 'attrs' => self::parseAttributes($rawAttributes), 'children' => array());
            if (isset($paired[$name])) { $stack[] = $node; continue; }
            $stack[$top]['children'][] = $node;
        }
        while (count($stack) > 1) {
            $node = array_pop($stack);
            $stack[count($stack) - 1]['children'][] = $node;
        }
        return $stack[0]['children'];
    }

    private function isShortcodeName($name) {
        return strpos($name, 'vc_') === 0 || strpos($name, 'mindcare') === 0 || strpos($name, 'woocommerce') === 0
            || isset(self::$drop[$name]) || isset(self::$unwrap[$name]);
    }

    /** WPBakery escapes a double quote inside an attribute value as ``. */
    private static function parseAttributes($raw) {
        $attrs = array();
        if (preg_match_all('~([a-zA-Z0-9_-]+)\s*=\s*"([^"]*)"~s', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $one) { $attrs[strtolower($one[1])] = str_replace('``', '"', $one[2]); }
        }
        return $attrs;
    }

    /** Decode WPBakery's `url:…|title:…|target:…` link attribute. */
    private static function parseLink($value) {
        $link = array('url' => '', 'title' => '', 'target' => '');
        foreach (explode('|', (string) $value) as $part) {
            $split = strpos($part, ':');
            if ($split === false) { continue; }
            $key = strtolower(substr($part, 0, $split));
            if (isset($link[$key])) { $link[$key] = rawurldecode(substr($part, $split + 1)); }
        }
        return $link;
    }

    /** Decode base64 payloads used by vc_raw_html and vc_gmaps. */
    private static function decodePayload($value) {
        $value = preg_replace('~^#E-\d+_~', '', trim((string) $value));
        if ($value === '' || !preg_match('~^[A-Za-z0-9+/=\s]+$~', $value)) { return ''; }
        $decoded = base64_decode(preg_replace('~\s+~', '', $value), true);
        return $decoded === false ? '' : rawurldecode($decoded);
    }

    /* ----------------------------------------------------------- rendering */

    /**
     * Literal text runs are concatenated as-authored; each expanded shortcode
     * becomes its own block so the HTML converter sees paragraph boundaries.
     */
    private function renderChildren(array $nodes) {
        $out = '';
        foreach ($nodes as $node) {
            if (isset($node['text'])) { $out .= $node['text']; continue; }
            $rendered = $this->renderNode($node);
            if (trim($rendered) === '') { continue; }
            if ($out !== '' && substr($out, -2) !== "\n\n") { $out .= "\n\n"; }
            $out .= $rendered;
            if (substr($rendered, -2) !== "\n\n") { $out .= "\n\n"; }
        }
        return $out;
    }

    private function renderNode(array $node) {
        $name = $node['name'];
        $attrs = $node['attrs'];
        $inner = $this->renderChildren($node['children']);
        if (isset(self::$drop[$name])) { $this->diagnostics['dropped:' . $name] = true; return ''; }
        if (isset(self::$unwrap[$name])) { return $inner; }

        switch ($name) {
            case 'mindcare_core_section_title':
                return $this->heading(self::attr($attrs, 'title'), self::attr($attrs, 'title_tag', 'h2'), self::attr($attrs, 'link'))
                    . $this->paragraph(self::attr($attrs, 'tagline'), true)
                    . $this->rawBlock(self::attr($attrs, 'text'))
                    . $inner;
            case 'vc_custom_heading':
                return $this->heading(self::attr($attrs, 'text'), 'h2', self::attr($attrs, 'link')) . $inner;
            case 'mindcare_core_custom_font':
                return $this->paragraph(self::attr($attrs, 'content_text')) . $inner;
            case 'vc_single_image':
                return $this->image(self::attr($attrs, 'image'), self::attr($attrs, 'alt'));
            case 'mindcare_core_image_with_text':
                return $this->image(self::attr($attrs, 'image')) . $inner;
            case 'mindcare_core_image_gallery':
                $images = '';
                foreach (explode(',', self::attr($attrs, 'images')) as $id) { $images .= $this->image(trim($id)); }
                return $images . $inner;
            case 'vc_btn':
            case 'mindcare_core_button':
                $link = self::parseLink(self::attr($attrs, 'link'));
                $label = self::attr($attrs, 'title');
                if ($label === '') { $label = self::attr($attrs, 'text'); }
                if ($label === '') { $label = $link['title']; }
                return $this->linkBlock($link['url'] !== '' ? $link['url'] : self::attr($attrs, 'link'), $label);
            case 'mindcare_core_video_button':
                return $this->linkBlock(self::attr($attrs, 'video_link'), 'Zobacz wideo');
            case 'vc_accordion_tab':
            case 'mindcare_core_accordion_child':
            case 'vc_tta_section':
                return $this->heading(self::attr($attrs, 'title'), 'h3') . $inner;
            case 'mindcare_core_icon_with_text':
                return $this->heading(self::attr($attrs, 'title'), 'h3')
                    . $this->paragraph(self::attr($attrs, 'text')) . $inner;
            case 'mindcare_core_icon_list_item':
                return $this->paragraph(self::attr($attrs, 'title')) . $inner;
            case 'mindcare_core_pricing_table':
                return $this->heading(self::attr($attrs, 'title'), 'h3')
                    . $this->paragraph(self::attr($attrs, 'price'))
                    . $this->rawBlock(self::attr($attrs, 'content')) . $inner;
            case 'vc_raw_html':
                return $this->rawBlock(self::decodePayload($this->plainText($node['children'])));
            case 'vc_gmaps':
                return $this->rawBlock(self::decodePayload(self::attr($attrs, 'link')));
            case 'vc_separator':
            case 'vc_hr':
                return '<hr>';
        }
        $this->diagnostics['unwrapped:' . $name] = true;
        return $inner;
    }

    private function plainText(array $nodes) {
        $text = '';
        foreach ($nodes as $node) { $text .= isset($node['text']) ? $node['text'] : $this->plainText($node['children']); }
        return $text;
    }

    private static function attr(array $attrs, $key, $default = '') {
        return isset($attrs[$key]) && trim($attrs[$key]) !== '' ? trim($attrs[$key]) : $default;
    }

    private function heading($text, $tag, $link = '') {
        if (trim((string) $text) === '') { return ''; }
        if (!preg_match('~^h[1-6]$~', (string) $tag)) { $tag = 'h2'; }
        $body = $text;
        $target = self::parseLink($link);
        $url = trim($target['url'] !== '' ? $target['url'] : (string) $link);
        if (preg_match('~^(?:https?://|/)~i', $url)) {
            $body = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $text . '</a>';
        }
        return '<' . $tag . '>' . $body . '</' . $tag . ">\n\n";
    }

    private function paragraph($text, $emphasis = false) {
        if (trim((string) $text) === '') { return ''; }
        return '<p>' . ($emphasis ? '<em>' . $text . '</em>' : $text) . "</p>\n\n";
    }

    private function rawBlock($html) {
        return trim((string) $html) === '' ? '' : $html . "\n\n";
    }

    private function linkBlock($url, $label) {
        $url = trim((string) $url);
        $label = trim((string) $label);
        if ($url === '') { return $label === '' ? '' : $this->paragraph($label); }
        if ($label === '') { $label = $url; }
        return '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $label . "</a></p>\n\n";
    }

    /** Resolve a WordPress attachment id to an <img> under the Pagecore uploads URL. */
    private function image($id, $alt = '') {
        $id = trim((string) $id);
        if ($id === '' || !isset($this->attachments[$id])) {
            if ($id !== '') { $this->diagnostics['image-unresolved'] = true; }
            return '';
        }
        $path = PagecoreWordPressImportPolicy::uploadRelativePath($this->attachments[$id]);
        if ($path === null) { $this->diagnostics['image-unsafe'] = true; return ''; }
        $src = $this->uploadsUrl . '/' . str_replace('%2F', '/', rawurlencode($path));
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="'
            . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . "\">\n\n";
    }
}
