<?php

require dirname(__DIR__) . '/cms/transport.php';

function transport_assert($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$production = array();
$direct = cms_transport_policy(array('HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.10'), $production, false);
transport_assert($direct['https'] && !$direct['reject'] && $direct['cookie_secure'] && $direct['hsts'], 'direct HTTPS policy');

$trustedConfig = array('trusted_proxies' => array('10.0.0.0/24'));
$proxied = cms_transport_policy(array(
    'REMOTE_ADDR' => '10.0.0.8',
    'HTTP_X_FORWARDED_PROTO' => 'https, http',
), $trustedConfig, false);
transport_assert($proxied['https'] && !$proxied['reject'] && $proxied['cookie_secure'], 'trusted proxy HTTPS');

$spoofed = cms_transport_policy(array(
    'REMOTE_ADDR' => '198.51.100.7',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_FORWARDED' => 'for=198.51.100.7;proto=https',
), $trustedConfig, false);
transport_assert(!$spoofed['https'] && $spoofed['reject'], 'untrusted forwarding headers');

$http = cms_transport_policy(array('REMOTE_ADDR' => '203.0.113.10'), $production, false);
transport_assert($http['reject'] && $http['cookie_secure'], 'production HTTP rejection');

$development = cms_transport_policy(array('REMOTE_ADDR' => '127.0.0.1'), array(), true);
transport_assert(!$development['reject'] && !$development['cookie_secure'] && !$development['hsts'], 'loopback development policy');

$forwarded = cms_transport_policy(array(
    'REMOTE_ADDR' => '2001:db8::5',
    'HTTP_FORWARDED' => 'for=192.0.2.1;proto="https";host=example.com',
), array('trusted_proxies' => array('2001:db8::/32')), false);
transport_assert($forwarded['https'], 'trusted IPv6 Forwarded header');

fwrite(STDOUT, "PASS: transport policy requires production HTTPS and trusts only configured proxies\n");
