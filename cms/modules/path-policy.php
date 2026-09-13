<?php

final class PagecorePathPolicy {
    public static function normalize($path) {
        $resolved = realpath($path);
        $value = $resolved !== false ? $resolved : (string) $path;
        $value = rtrim(str_replace('\\', '/', $value), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
    }

    public static function isWithin($path, $root) {
        $path = self::normalize($path);
        $root = self::normalize($root);
        return $path === $root || strpos($path, $root . '/') === 0;
    }

    public static function resolveWithin($root, $relative, $mustExist = true) {
        $raw = (string) $relative;
        if ($raw === '' || strpos($raw, "\0") !== false || preg_match('~%2f|%5c~i', $raw)) { return null; }
        $relative = str_replace('\\', '/', rawurldecode($raw));
        if ($relative === '' || $relative[0] === '/' || preg_match('~^[A-Za-z]:~', $relative)) { return null; }
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') { return null; }
        }
        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) { return null; }
        $candidate = rtrim($rootReal, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (file_exists($candidate) || is_link($candidate)) {
            $resolved = realpath($candidate);
            return $resolved !== false && self::isWithin($resolved, $rootReal) && (!$mustExist || file_exists($resolved)) ? $resolved : null;
        }
        if ($mustExist) { return null; }
        $parent = dirname($candidate);
        while (!file_exists($parent) && dirname($parent) !== $parent) { $parent = dirname($parent); }
        $parentReal = realpath($parent);
        return $parentReal !== false && self::isWithin($parentReal, $rootReal) ? $candidate : null;
    }

    public static function relativeTo($path, $root) {
        $real = realpath($path);
        $rootReal = realpath($root);
        if ($real === false || $rootReal === false || !self::isWithin($real, $rootReal) || self::normalize($real) === self::normalize($rootReal)) { return null; }
        return ltrim(substr(str_replace('\\', '/', $real), strlen(rtrim(str_replace('\\', '/', $rootReal), '/'))), '/');
    }
}
