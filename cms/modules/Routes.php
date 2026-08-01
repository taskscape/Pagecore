<?php

final class PagecoreRoutes {
    public static function normalizePrefix($value) {
        if (!is_string($value)) { return null; }
        $value = trim($value);
        if ($value === '' || $value[0] !== '/' || strpos($value, '\\') !== false
            || strpos($value, '?') !== false || strpos($value, '#') !== false
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $value)) {
            return null;
        }
        $value = preg_replace('~/+~', '/', $value);
        return $value === '/' ? '/' : rtrim($value, '/');
    }

    public static function join($prefix, $path = '') {
        $prefix = self::normalizePrefix($prefix);
        if ($prefix === null) { throw new InvalidArgumentException('Invalid route prefix.'); }
        $path = (string) $path;
        if ($path === '') { return $prefix; }
        if (strpos($path, '\\') !== false || preg_match('~^(?:[a-z][a-z0-9+.-]*:)?//~i', $path)) {
            throw new InvalidArgumentException('Route path must be local.');
        }
        $suffix = '';
        $split = strcspn($path, '?#');
        if ($split < strlen($path)) {
            $suffix = substr($path, $split);
            $path = substr($path, 0, $split);
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') { throw new InvalidArgumentException('Route traversal is not allowed.'); }
        }
        $joined = ($prefix === '/' ? '/' : $prefix . '/') . ltrim($path, '/');
        return ($path === '' ? $prefix : preg_replace('~/+~', '/', $joined)) . $suffix;
    }

    public static function isLocalRoute($value) {
        if (!is_string($value) || $value === '' || $value[0] !== '/' || strpos($value, '\\') !== false) { return false; }
        $path = parse_url($value, PHP_URL_PATH);
        if (!is_string($path) || strpos($value, '//') === 0) { return false; }
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ($segment === '.' || $segment === '..') { return false; }
        }
        return true;
    }
}
