<?php

define('CMS_CONFIG_FILE', dirname(__DIR__) . '/sample-site/config.php');
require dirname(__DIR__) . '/cms/auth.php';

function login_request_check($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

// The public canonical URL can differ from the hostname serving the CMS.
$GLOBALS['CMS_CONFIG']['site_url'] = 'https://www.example.com';
$GLOBALS['CMS_CONFIG']['trusted_proxies'] = array('10.0.0.0/24');
$direct = array('HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'REMOTE_ADDR' => '192.0.2.10');
$_SESSION = array();
$token = cms_login_csrf_token();

$cases = array(
    array('current origin despite different canonical hostname', $direct, 'https://example.com', true),
    array('canonical hostname is still a different origin', $direct, 'https://www.example.com', false),
    array('unrelated hostname', $direct, 'https://attacker.example', false),
    array('different scheme', $direct, 'http://example.com', false),
    array('different port', $direct, 'https://example.com:8443', false),
    array('explicit default port', $direct, 'https://EXAMPLE.com:443', true),
    array('opaque origin', $direct, 'null', false),
    array('malformed origin', $direct, '://example.com', false),
    array('local HTTP with a different canonical URL', array('HTTP_HOST' => '127.0.0.1:8765'), 'http://127.0.0.1:8765', true),
    array('IPv6 authority', array('HTTP_HOST' => '[::1]:8765'), 'http://[::1]:8765', true),
    array('trusted TLS proxy', array('HTTP_HOST' => 'example.com', 'REMOTE_ADDR' => '10.0.0.8', 'HTTP_X_FORWARDED_PROTO' => 'https'), 'https://example.com', true),
    array('untrusted TLS forwarding', array('HTTP_HOST' => 'example.com', 'REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_PROTO' => 'https'), 'https://example.com', false),
    array('forwarded hostname is not trusted', $direct + array('HTTP_X_FORWARDED_HOST' => 'attacker.example'), 'https://attacker.example', false),
    array('invalid host authority', array('HTTP_HOST' => 'example.com/path', 'HTTPS' => 'on'), 'https://example.com', false),
    array('host with user information', array('HTTP_HOST' => 'user@example.com', 'HTTPS' => 'on'), 'https://example.com', false),
);
foreach ($cases as list($label, $server, $origin, $allowed)) {
    $_SERVER = $server + array('HTTP_ORIGIN' => $origin);
    login_request_check(cms_verify_login_request($token) === $allowed, $label);
}

$_SERVER = $direct + array('HTTP_REFERER' => 'https://example.com/cms/login.php?next=%2F');
login_request_check(cms_verify_login_request($token), 'same-origin referrer fallback');
$_SERVER['HTTP_REFERER'] = 'https://attacker.example/cms/login.php';
login_request_check(!cms_verify_login_request($token), 'cross-origin referrer fallback');
$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
$_SERVER['HTTP_REFERER'] = 'https://example.com/cms/login.php';
login_request_check(!cms_verify_login_request($token), 'Origin must take precedence over Referer');

$_SERVER = $direct + array('HTTP_ORIGIN' => 'https://example.com');
login_request_check(!cms_verify_login_request(''), 'missing token must fail');
login_request_check(!cms_verify_login_request(str_repeat('0', 64)), 'incorrect token must fail');
unset($_SESSION['cms_login_csrf']);
login_request_check(!cms_verify_login_request($token), 'expired session must fail');
$replacement = cms_login_csrf_token();
login_request_check($replacement !== $token && cms_verify_login_request($replacement), 'new form must recover after session expiry');

fwrite(STDOUT, "PASS: login requests use the serving origin, enforce tokens, and reject cross-site submissions\n");
