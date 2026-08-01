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
}
