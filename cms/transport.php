<?php

function cms_ip_matches_cidr($ip, $rule) {
    if (strpos($rule, '/') === false) { return hash_equals((string) $rule, (string) $ip); }
    list($network, $prefix) = explode('/', $rule, 2);
    $ipBytes = @inet_pton($ip);
    $networkBytes = @inet_pton($network);
    if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) { return false; }
    $prefix = (int) $prefix;
    $max = strlen($ipBytes) * 8;
    if ($prefix < 0 || $prefix > $max) { return false; }
    for ($i = 0; $i < strlen($ipBytes); $i++) {
        $bits = min(8, max(0, $prefix - ($i * 8)));
        if ($bits === 0) { break; }
        $mask = (0xff << (8 - $bits)) & 0xff;
        if ((ord($ipBytes[$i]) & $mask) !== (ord($networkBytes[$i]) & $mask)) { return false; }
    }
    return true;
}

function cms_transport_proxy_is_trusted($remoteAddress, $trustedProxies) {
    foreach ((array) $trustedProxies as $rule) {
        if (cms_ip_matches_cidr((string) $remoteAddress, (string) $rule)) { return true; }
    }
    return false;
}

function cms_transport_is_https($server, $trustedProxies = array()) {
    $https = isset($server['HTTPS']) ? strtolower((string) $server['HTTPS']) : '';
    if ($https !== '' && $https !== 'off' && $https !== '0') { return true; }

    $remote = isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : '';
    if (!cms_transport_proxy_is_trusted($remote, $trustedProxies)) { return false; }
    if (isset($server['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(trim(explode(',', (string) $server['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($proto === 'https') { return true; }
    }
    return isset($server['HTTP_FORWARDED'])
        && preg_match('/(?:^|[,;]\s*)proto="?https"?(?:[;,]|$)/i', (string) $server['HTTP_FORWARDED']) === 1;
}

function cms_transport_policy($server, $config, $development = false) {
    $trusted = isset($config['trusted_proxies']) ? (array) $config['trusted_proxies'] : array();
    $https = cms_transport_is_https($server, $trusted);
    $requireHttps = array_key_exists('require_https', $config) ? (bool) $config['require_https'] : !$development;
    $cookieSecure = array_key_exists('cookie_secure', $config) ? (bool) $config['cookie_secure'] : !$development;
    $hsts = array_key_exists('hsts', $config) ? (bool) $config['hsts'] : !$development;
    return array(
        'https' => $https,
        'reject' => $requireHttps && !$https,
        'cookie_secure' => $cookieSecure,
        'hsts' => $hsts && $https,
        'hsts_max_age' => isset($config['hsts_max_age']) ? max(0, (int) $config['hsts_max_age']) : 31536000,
        'hsts_include_subdomains' => !empty($config['hsts_include_subdomains']),
    );
}
