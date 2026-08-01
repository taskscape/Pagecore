<?php
require_once dirname(__DIR__, 2) . '/cms/modules/SlugPolicy.php';
require_once dirname(__DIR__, 2) . '/cms/modules/JsonPolicy.php';

final class PagecoreWordPressImportPolicy {
    public static function decodeSerializedOption($value) {
        if (!is_string($value) || $value === '') { return null; }
        $decoded = @unserialize($value, array('allowed_classes' => false));
        return $decoded === false && $value !== 'b:0;' ? null : $decoded;
    }

    public static function uploadRelativePath($path) {
        $path = rawurldecode((string) $path);
        if ($path === '' || strpos($path, "\0") !== false) { return null; }
        $path = str_replace('\\', '/', $path);
        if ($path[0] === '/' || preg_match('~^[A-Za-z]:~', $path)) { return null; }
        foreach (explode('/', $path) as $part) { if ($part === '' || $part === '.' || $part === '..') { return null; } }
        return $path;
    }

    public static function rewriteUploads($text, $uploadsUrl) {
        return preg_replace('~(?:https?://[^\s"\'<>()]*?)?/(?:[a-z0-9_-]+/)?wp-content/uploads/~i',
            rtrim((string) $uploadsUrl, '/') . '/', (string) $text);
    }

    public static function internalUrl($url, array $siteUrls) {
        $url = trim((string) $url);
        if ($url === '') { return '#'; }
        if ($url[0] === '/') { return preg_replace('~^/blog(?=/|$)~', '', $url); }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) { return $url; }
        $host = strtolower($parts['host']);
        foreach ($siteUrls as $siteUrl) {
            $site = parse_url($siteUrl);
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

    public static function uniqueSlug($slug, array &$seen) {
        return PagecoreSlugPolicy::uniqueContentSlug($slug, $seen);
    }

    public static function contentSlug($value) { return PagecoreSlugPolicy::contentSlug(rawurldecode((string) $value)); }

    public static function frontMatterValue($value) { return str_replace(array("\r", "\n"), ' ', (string) $value); }
}
