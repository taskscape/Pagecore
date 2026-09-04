<?php
require_once __DIR__ . '/modules/routes.php';
require_once __DIR__ . '/modules/update-policy.php';

/** Defaults for the auto-update feature, applied before path validation. */
function cms_update_defaults($config) {
    if (!isset($config['update_channel'])) { $config['update_channel'] = 'main'; }
    if (!isset($config['update_manifest_url'])) {
        $config['update_manifest_url'] = 'https://raw.githubusercontent.com/taskscape/Pagecore/main/release/latest.json';
    }
    if (!isset($config['update_allowed_hosts'])) {
        $config['update_allowed_hosts'] = array('raw.githubusercontent.com', 'github.com', 'objects.githubusercontent.com');
    }
    if (!isset($config['update_preserve'])) { $config['update_preserve'] = array('config.php'); }
    if (!isset($config['update_cron_key'])) { $config['update_cron_key'] = ''; }
    // Optional trust store for hosts whose PHP ships without a CA bundle.
    // It selects which authorities are trusted; it cannot disable verification.
    if (!isset($config['update_ca_bundle'])) { $config['update_ca_bundle'] = ''; }
    foreach (array('update_apply' => false, 'update_auto_apply' => true, 'update_check_on_admin' => true, 'update_allow_downgrade' => false) as $key => $default) {
        if (!isset($config[$key])) { $config[$key] = $default; }
    }
    foreach (array(
        'update_check_ttl_seconds' => 21600,
        'update_cron_min_interval_seconds' => 300,
        'update_keep' => 3,
        'update_http_timeout_seconds' => 20,
        'update_download_timeout_seconds' => 120,
        'update_max_archive_bytes' => 26214400,
    ) as $key => $default) {
        if (!isset($config[$key])) { $config[$key] = $default; }
    }
    if (!isset($config['update_state_dir'])) {
        $config['update_state_dir'] = isset($config['login_rate_limit_dir']) && is_string($config['login_rate_limit_dir'])
            ? $config['login_rate_limit_dir']
            : (isset($config['content_dir']) && is_string($config['content_dir']) ? $config['content_dir'] . '/.state' : '');
    }
    if (!isset($config['update_work_dir']) && is_string($config['update_state_dir']) && $config['update_state_dir'] !== '') {
        $config['update_work_dir'] = rtrim($config['update_state_dir'], '/\\') . '/updates';
    }
    return $config;
}

/** Validate the auto-update keys. Returns a list of error strings. */
function cms_update_config_errors($config) {
    $errors = array();
    if (!in_array($config['update_channel'], array('main', 'off'), true)) {
        $errors[] = 'update_channel must be main or off';
    }
    foreach (array('update_apply', 'update_auto_apply', 'update_check_on_admin', 'update_allow_downgrade') as $key) {
        if (!is_bool($config[$key])) { $errors[] = $key . ' must be boolean'; }
    }
    foreach (array('update_check_ttl_seconds', 'update_cron_min_interval_seconds', 'update_http_timeout_seconds', 'update_download_timeout_seconds', 'update_max_archive_bytes') as $key) {
        if (!is_int($config[$key]) || $config[$key] <= 0) { $errors[] = $key . ' must be a positive integer'; }
    }
    if (!is_int($config['update_keep']) || $config['update_keep'] < 0) { $errors[] = 'update_keep must be zero or a positive integer'; }
    if (!is_array($config['update_allowed_hosts']) || !$config['update_allowed_hosts']) {
        $errors[] = 'update_allowed_hosts must be a non-empty array';
    } else {
        foreach ($config['update_allowed_hosts'] as $host) {
            if (!is_string($host) || preg_match('~^[a-z0-9.-]+$~i', $host) !== 1) { $errors[] = 'update_allowed_hosts contains an invalid host'; break; }
        }
    }
    if (!$errors && PagecoreUpdatePolicy::allowedHost($config['update_manifest_url'], (array) $config['update_allowed_hosts']) === null) {
        $errors[] = 'update_manifest_url must be an HTTPS URL on a host listed in update_allowed_hosts';
    }
    if (!is_array($config['update_preserve'])) {
        $errors[] = 'update_preserve must be an array';
    } else {
        foreach ($config['update_preserve'] as $entry) {
            if (!is_string($entry) || preg_match('~(?:^|/)\.\.?(?:/|$)~', $entry) === 1 || $entry === '' || $entry[0] === '/' || strpos($entry, '\\') !== false) {
                $errors[] = 'update_preserve contains an invalid relative path';
                break;
            }
        }
    }
    if (!is_string($config['update_ca_bundle'])) {
        $errors[] = 'update_ca_bundle must be a string';
    } elseif ($config['update_ca_bundle'] !== '' && !cms_config_is_absolute_path($config['update_ca_bundle'])) {
        $errors[] = 'update_ca_bundle must be an absolute path';
    }
    // An empty key disables the cron endpoint; a short or placeholder key is a misconfiguration.
    $key = $config['update_cron_key'];
    if (!is_string($key)) {
        $errors[] = 'update_cron_key must be a string';
    } elseif ($key !== '') {
        if (strlen($key) < 32) { $errors[] = 'update_cron_key must be at least 32 characters'; }
        if (stripos($key, 'REPLACE_WITH') === 0) { $errors[] = 'update_cron_key still holds the example placeholder'; }
    }
    if (!empty($config['update_apply'])) {
        foreach (array('update_state_dir', 'update_work_dir') as $directory) {
            if (!isset($config[$directory]) || !is_string($config[$directory]) || trim($config[$directory]) === '') {
                $errors[] = $directory . ' must be set when update_apply is enabled';
            }
        }
    }
    return $errors;
}

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
    if (!isset($config['generated_dir']) && isset($config['site_root'])) { $config['generated_dir'] = $config['site_root']; }
    if (!isset($config['timezone'])) { $config['timezone'] = 'UTC'; }
    $config = cms_update_defaults($config);
    $requiredStrings =array('session_name', 'username', 'password_hash', 'content_dir', 'backup_dir', 'site_root', 'site_url', 'site_name', 'timezone', 'uploads_dir', 'uploads_url', 'post_url', 'base_url', 'cms_url');
    foreach ($requiredStrings as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '') { $errors[] = $key . ' must be a non-empty string'; }
    }
    foreach (array('content_dir', 'backup_dir', 'site_root', 'generated_dir', 'uploads_dir', 'login_rate_limit_dir', 'audit_log_path', 'update_state_dir', 'update_work_dir') as $key) {
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
    if (isset($config['timezone'])) {
        try { new DateTimeZone($config['timezone']); }
        catch (Throwable $error) { $errors[] = 'timezone must be a valid IANA timezone'; }
    }
    if (isset($config['post_url'])) {
        $placeholders = substr_count($config['post_url'], '{slug}');
        if ($placeholders > 1) { $errors[] = 'post_url must contain {slug} at most once'; }
        elseif ($placeholders === 0) { $config['post_url'] = rtrim($config['post_url'], '/') . '/{slug}/'; }
    }
    foreach (array('base_url', 'cms_url') as $key) {
        if (isset($config[$key])) {
            $normalized = PagecoreRoutes::normalizePrefix($config[$key]);
            if ($normalized === null) { $errors[] = $key . ' must be a root-relative URL prefix without traversal, query, or fragment'; }
            else { $config[$key] = $normalized; }
        }
    }

    if (!isset($config['external_edit_validation'])) { $config['external_edit_validation'] = true; }
    if (!isset($config['rendered_content_cache'])) { $config['rendered_content_cache'] = false; }
    foreach (array('development_only', 'demo_credentials', 'require_https', 'cookie_secure', 'hsts', 'external_edit_validation', 'rendered_content_cache') as $key) {
        if (!array_key_exists($key, $config) || !is_bool($config[$key])) { $errors[] = $key . ' must be boolean'; }
    }
    if (!isset($config['static_media_references'])) { $config['static_media_references'] = array(); }
    if (!isset($config['template_roots'])) { $config['template_roots'] = array(''); }
    foreach (array('categories', 'search_pages', 'allowed_ext', 'trusted_proxies', 'sitemap_extra_routes', 'static_media_references', 'template_roots') as $key) {
        if (!isset($config[$key]) || !is_array($config[$key])) { $errors[] = $key . ' must be an array'; }
    }
    if (isset($config['template_roots']) && is_array($config['template_roots'])) {
        foreach ($config['template_roots'] as $root) {
            if (!is_string($root) || preg_match('~(?:^|[\\/])\.\.?(?:[\\/]|$)~', $root) || cms_config_is_absolute_path($root)) {
                $errors[] = 'template_roots contains an invalid relative path'; break;
            }
        }
        $config['template_roots'] = array_values(array_unique($config['template_roots']));
    }
    if (isset($config['static_media_references']) && is_array($config['static_media_references'])) {
        foreach ($config['static_media_references'] as $route) {
            if (!PagecoreRoutes::isLocalRoute($route)) { $errors[] = 'static_media_references contains an invalid local route'; break; }
        }
        $config['static_media_references'] = array_values(array_unique($config['static_media_references']));
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
    if (isset($config['date_months'])) {
        $months = $config['date_months'];
        $valid = is_array($months) && count($months) === 12;
        if ($valid) {
            foreach ($months as $month) {
                if (!is_string($month) || trim($month) === '') { $valid = false; break; }
            }
        }
        if (!$valid) { $errors[] = 'date_months must be a list of 12 non-empty strings'; }
        else { $config['date_months'] = array_values($months); }
    }
    if (isset($config['allowed_ext']) && is_array($config['allowed_ext'])) {
        $extensions = array_values(array_unique(array_map('strtolower', $config['allowed_ext'])));
        if (!$extensions || array_diff($extensions, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'))) { $errors[] = 'allowed_ext contains an unsafe or unsupported extension'; }
        $config['allowed_ext'] = $extensions;
    }
    $positiveIntegers = array('session_hours', 'backup_keep', 'login_rate_window_seconds', 'login_rate_source_limit', 'login_rate_account_limit', 'audit_max_bytes', 'max_upload_mb', 'max_request_bytes', 'max_content_bytes', 'max_nav_bytes', 'max_metadata_bytes', 'max_title_bytes', 'max_identifier_bytes', 'max_query_bytes', 'max_image_width', 'max_image_height', 'max_image_pixels', 'max_upload_storage_bytes', 'max_upload_files', 'max_uploads_per_month', 'media_page_size', 'max_media_page_size', 'max_inventory_page_size', 'max_inventory_items', 'max_template_files', 'max_search_query_bytes', 'max_search_index_bytes', 'max_search_index_items', 'max_search_results', 'search_results_per_page');
    foreach ($positiveIntegers as $key) {
        if (!isset($config[$key]) || !is_int($config[$key]) || $config[$key] <= 0) { $errors[] = $key . ' must be a positive integer'; }
    }
    $errors = array_merge($errors, cms_update_config_errors($config));
    if ($production) {
        if (!empty($config['development_only']) || !empty($config['demo_credentials'])) { $errors[] = 'production cannot use development or demo credentials'; }
        foreach (array('require_https', 'cookie_secure', 'hsts') as $key) {
            if (empty($config[$key])) { $errors[] = 'production requires ' . $key; }
        }
        if (isset($config['site_url']) && stripos($config['site_url'], 'https://') !== 0) { $errors[] = 'production site_url must use HTTPS'; }
    }
    return array($config, array_values(array_unique($errors)));
}
