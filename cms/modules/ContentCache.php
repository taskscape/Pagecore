<?php

final class PagecoreContentCache {
    /** Return a stable relative-path => mtime:size manifest without reading post bodies. */
    public static function manifest($postsDirectory) {
        $files = array();
        if (!is_dir($postsDirectory)) { return $files; }
        foreach (glob(rtrim($postsDirectory, '/\\') . DIRECTORY_SEPARATOR . '*.md') ?: array() as $path) {
            $mtime = filemtime($path);
            $size = filesize($path);
            if ($mtime === false || $size === false) { return null; }
            $files[basename($path)] = (string) $mtime . ':' . (string) $size;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    public static function manifestMatches($json, $postsDirectory) {
        $stored = json_decode((string) $json, true);
        if (!is_array($stored) || !isset($stored['version'], $stored['files']) || $stored['version'] !== 1 || !is_array($stored['files'])) { return false; }
        $current = self::manifest($postsDirectory);
        return is_array($current) && $stored['files'] === $current;
    }

    public static function manifestJson($postsDirectory) {
        $files = self::manifest($postsDirectory);
        if (!is_array($files)) { return false; }
        return json_encode(array('version' => 1, 'files' => $files), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function renderKey($markdown, $rendererIdentity, $safetyPolicy) {
        return hash('sha256', (string) $rendererIdentity . "\0" . (string) $safetyPolicy . "\0" . (string) $markdown);
    }

    /** Cache only already-sanitized renderer output; callers retain atomic-write policy. */
    public static function rendered($markdown, $enabled, $cacheDirectory, $rendererIdentity, $safetyPolicy, callable $renderer, callable $writer) {
        if (!$enabled) { return $renderer($markdown); }
        $key = self::renderKey($markdown, $rendererIdentity, $safetyPolicy);
        $path = rtrim($cacheDirectory, '/\\') . DIRECTORY_SEPARATOR . $key . '.html';
        if (is_file($path)) {
            $cached = file_get_contents($path);
            if ($cached !== false) { return $cached; }
        }
        $html = (string) $renderer($markdown);
        $writer($path, $html);
        return $html;
    }
}
