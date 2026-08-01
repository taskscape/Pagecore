<?php
require_once __DIR__ . '/modules/PathPolicy.php';

/**
 * Normalize a request path without allowing ambiguous traversal or separator
 * spellings. Returns false when the request must be rejected.
 */
function pagecore_request_path($requestUri) {
    $path = parse_url((string) $requestUri, PHP_URL_PATH);
    if (!is_string($path) || $path === '' || $path[0] !== '/') { return false; }

    for ($i = 0; $i < 3; $i++) {
        if (strpos($path, "\\") !== false || preg_match('/[\x00-\x1f\x7f]/', $path)) { return false; }
        $decoded = rawurldecode($path);
        if ($decoded === $path) { break; }
        $path = $decoded;
    }

    if (preg_match('/%(?:00|2e|2f|5c)/i', $path)
        || strpos($path, "\\") !== false
        || preg_match('/[\x00-\x1f\x7f]/', $path)) {
        return false;
    }

    foreach (explode('/', $path) as $segment) {
        if ($segment === '.' || $segment === '..') { return false; }
    }
    return preg_replace('~/+~', '/', $path);
}

function pagecore_request_path_has_prefix($path, $prefix) {
    $prefix = rtrim($prefix, '/');
    return $path === $prefix || strpos($path, $prefix . '/') === 0;
}

/** Apply the public CMS allowlist and deployment-specific private roots. */
function pagecore_request_is_denied($requestUri, $privatePrefixes = array(), $uploadPrefixes = array()) {
    $path = pagecore_request_path($requestUri);
    if ($path === false) { return true; }

    foreach ($privatePrefixes as $prefix) {
        if (pagecore_request_path_has_prefix($path, $prefix)) { return true; }
    }

    foreach ($uploadPrefixes as $prefix) {
        if (pagecore_request_path_has_prefix($path, $prefix)
            && preg_match('/\.(?:php\d*|phtml|phar|pht|cgi|pl)(?:$|\/)/i', $path)) {
            return true;
        }
    }

    if (pagecore_request_path_has_prefix($path, '/cms')) {
        $publicEndpoints = array(
            '/cms/api.php',
            '/cms/content.php',
            '/cms/login.php',
            '/cms/media-file.php',
            '/cms/media.php',
        );
        if (in_array($path, $publicEndpoints, true)) { return false; }
        if (preg_match('~^/cms/assets/(?:admin|admin-client|dialog|editor|editor-state|editor-view|tokens)\.(?:css|js)$~', $path)) { return false; }
        return true;
    }

    return false;
}

/** Resolve a static file only when it remains below the exact root boundary. */
function pagecore_public_file($root, $path) {
    $candidate = PagecorePathPolicy::resolveWithin($root, ltrim((string) $path, '/'), true);
    return $candidate !== null && is_file($candidate) ? $candidate : false;
}
