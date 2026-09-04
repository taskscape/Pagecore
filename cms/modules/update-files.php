<?php
require_once __DIR__ . '/operational-boundary.php';

/** Directory-tree primitives shared by extraction, snapshots, and rollback. */
final class PagecoreUpdateFiles {

    /**
     * Normalize an archive entry name to a safe relative path, or null.
     * Rejects absolute paths, drive letters, traversal, separators that differ
     * per platform, control characters, and empty segments.
     */
    public static function safeRelativePath($name) {
        $name = (string) $name;
        if ($name === '' || strlen($name) > 1024) { return null; }
        if (strpos($name, "\0") !== false || preg_match('~[\x00-\x1f\x7f]~', $name)) { return null; }
        if (strpos($name, '\\') !== false) { return null; }
        if ($name[0] === '/' || preg_match('~^[A-Za-z]:~', $name)) { return null; }
        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') { return null; }
        }
        return implode('/', $segments);
    }

    /** Every regular file below $root, as paths relative to $root, sorted. */
    public static function listTree($root, $prefix = '') {
        $found = array();
        $base = rtrim((string) $root, '/\\');
        $directory = $prefix === '' ? $base : $base . '/' . $prefix;
        $items = PagecoreOperationalBoundary::directoryItems($directory, 'update.scan');
        if (!$items->ok) { return $found; }
        foreach ((array) $items->value as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $relative = $prefix === '' ? $item : $prefix . '/' . $item;
            $absolute = $base . '/' . $relative;
            if (is_link($absolute)) { continue; }
            if (is_dir($absolute)) {
                $found = array_merge($found, self::listTree($base, $relative));
                continue;
            }
            if (is_file($absolute)) { $found[] = $relative; }
        }
        sort($found);
        return $found;
    }

    public static function copyTree($source, $target) {
        if (!is_dir($source)) { return false; }
        if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) { return false; }
        foreach (self::listTree($source) as $relative) {
            $destination = $target . '/' . $relative;
            $directory = dirname($destination);
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) { return false; }
            if (!@copy($source . '/' . $relative, $destination)) { return false; }
        }
        return true;
    }

    public static function removeTree($path) {
        if (is_link($path) || is_file($path)) { return PagecoreOperationalBoundary::delete($path, 'update.cleanup')->ok; }
        if (!is_dir($path)) { return true; }
        $items = PagecoreOperationalBoundary::directoryItems($path, 'update.cleanup.scan');
        if (!$items->ok) { return false; }
        $ok = true;
        foreach ((array) $items->value as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $ok = self::removeTree($path . '/' . $item) && $ok;
        }
        return PagecoreOperationalBoundary::removeDirectory($path, 'update.cleanup.rmdir')->ok && $ok;
    }

    /** Total bytes of a tree, used for disk accounting before a snapshot. */
    public static function treeBytes($root) {
        $total = 0;
        foreach (self::listTree($root) as $relative) {
            $size = @filesize($root . '/' . $relative);
            if ($size !== false) { $total += (int) $size; }
        }
        return $total;
    }

    /** A directory is usable when we can create and remove an entry inside it. */
    public static function isWritableDirectory($path) {
        if (!is_dir($path) || !is_writable($path)) { return false; }
        $probe = rtrim($path, '/\\') . '/.pagecore-write-probe-' . bin2hex(random_bytes(6));
        $handle = @fopen($probe, 'x+b');
        if (!$handle) { return false; }
        fclose($handle);
        return PagecoreOperationalBoundary::delete($probe, 'update.probe')->ok;
    }
}
