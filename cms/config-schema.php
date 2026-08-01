<?php
require_once __DIR__ . '/modules/Routes.php';

function cms_config_is_absolute_path($path) {
    return is_string($path) && ($path !== '') && ($path[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $path));
}

/** Validate and normalize the complete configuration before any runtime side effects. */
function cms_validate_config($config, $production) {
    $errors = array();
    if (!is_array($config)) { return array(null, array('configuration must return an array')); }

    if (!isset($config['base_url'])) { $config['base_url'] = '/'; }
    if (!isset($config['cms_url'])) { $config['cms_url'] = '/cms'; }
    if (!isset($config['sitemap_extra_routes'])) { $config['sitemap_extra_routes'] = array(); }
    $requiredStrings = array('session_name', 'username', 'password_hash', 'content_dir', 'backup_dir', 'site_root', 'site_url', 'site_name', 'uploads_dir', 'uploads_url', 'post_url', 'base_url', 'cms_url');
    foreach ($requiredStrings as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '') { $errors[] = $key . ' must be a non-empty string'; }
    }
    foreach (array('content_dir', 'backup_dir', 'site_root', 'uploads_dir', 'login_rate_limit_dir', 'audit_log_path') as $key) {
        if (isset($config[$key]) && !cms_config_is_absolute_path($config[$key])) { $errors[] = $key . ' must be an absolute path'; }
    }
    $pathKeys = array('content_dir', 'backup_dir', 'uploads_dir');
    $seenPaths = array();
    foreach ($pathKeys as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) { continue; }
        $normalized = strtolower(rtrim(str_replace('\\', '/', $config[$key]), '/'));
        if (isset($seenPaths[$normalized])) { $errors[] = $key . ' must not equal ' . $seenPaths[$normalized]; }
        $seenPaths[$normalized] = $key;
        $config[$key] = rtrim($config[$key], '/\\');
    }
    if (isset($config['session_name']) && !preg_match('~^[A-Za-z][A-Za-z0-9_-]{0,63}$~', $config['session_name'])) {
        $errors[] = 'session_name contains unsupported characters';
    }
    if (isset($config['password_hash']) && password_get_info($config['password_hash'])['algoName'] === 'unknown') {
        $errors[] = 'password_hash is not a supported password hash';
    }
    if (isset($config['site_url']) && filter_var($config['site_url'], FILTER_VALIDATE_URL) === false) { $errors[] = 'site_url must be an absolute URL'; }
    if (isset($config['post_url']) && substr_count($config['post_url'], '{slug}') !== 1) { $errors[] = 'post_url must contain {slug} exactly once'; }
    foreach (array('base_url', 'cms_url') as $key) {
        if (isset($config[$key])) {
            $normalized = PagecoreRoutes::normalizePrefix($config[$key]);
            if ($normalized === null) { $errors[] = $key . ' must be a root-relative URL prefix without traversal, query, or fragment'; }
            else { $config[$key] = $normalized; }
        }
    }

    foreach (array('development_only', 'demo_credentials', 'require_https', 'cookie_secure', 'hsts') as $key) {
        if (!array_key_exists($key, $config) || !is_bool($config[$key])) { $errors[] = $key . ' must be boolean'; }
    }
    foreach (array('categories', 'search_pages', 'allowed_ext', 'trusted_proxies', 'sitemap_extra_routes') as $key) {
        if (!isset($config[$key]) || !is_array($config[$key])) { $errors[] = $key . ' must be an array'; }
    }
    if (isset($config['sitemap_extra_routes']) && is_array($config['sitemap_extra_routes'])) {
        foreach ($config['sitemap_extra_routes'] as $route) {
            if (!PagecoreRoutes::isLocalRoute($route)) { $errors[] = 'sitemap_extra_routes contains an invalid local route'; break; }
        }
        $config['sitemap_extra_routes'] = array_values(array_unique($config['sitemap_extra_routes']));
    }
    if (isset($config['categories']) && is_array($config['categories'])) {
        foreach ($config['categories'] as $slug => $definition) {
            if (!preg_match('~^[a-z0-9-]+$~', (string) $slug) || !is_array($definition) || count($definition) < 2 || trim((string) $definition[0]) === '' || !preg_match('~^(https?://|/)~', (string) $definition[1])) {
                $errors[] = 'categories contains an invalid definition';
                break;
            }
        }
    }
    if (isset($config['allowed_ext']) && is_array($config['allowed_ext'])) {
        $extensions = array_values(array_unique(array_map('strtolower', $config['allowed_ext'])));
        if (!$extensions || array_diff($extensions, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'))) { $errors[] = 'allowed_ext contains an unsafe or unsupported extension'; }
        $config['allowed_ext'] = $extensions;
    }
    $positiveIntegers = array('session_hours', 'backup_keep', 'login_rate_window_seconds', 'login_rate_source_limit', 'login_rate_account_limit', 'audit_max_bytes', 'max_upload_mb', 'max_request_bytes', 'max_content_bytes', 'max_nav_bytes', 'max_metadata_bytes', 'max_title_bytes', 'max_identifier_bytes', 'max_query_bytes', 'max_image_width', 'max_image_height', 'max_image_pixels', 'max_upload_storage_bytes', 'max_upload_files', 'max_uploads_per_month', 'media_page_size', 'max_media_page_size', 'max_inventory_page_size', 'max_inventory_items', 'max_search_query_bytes', 'max_search_index_bytes', 'max_search_index_items', 'max_search_results', 'search_results_per_page');
    foreach ($positiveIntegers as $key) {
        if (!isset($config[$key]) || !is_int($config[$key]) || $config[$key] <= 0) { $errors[] = $key . ' must be a positive integer'; }
    }
    if ($production) {
        if (!empty($config['development_only']) || !empty($config['demo_credentials'])) { $errors[] = 'production cannot use development or demo credentials'; }
        foreach (array('require_https', 'cookie_secure', 'hsts') as $key) {
            if (empty($config[$key])) { $errors[] = 'production requires ' . $key; }
        }
        if (isset($config['site_url']) && stripos($config['site_url'], 'https://') !== 0) { $errors[] = 'production site_url must use HTTPS'; }
    }
    return array($config, array_values(array_unique($errors)));
}
