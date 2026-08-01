<?php

$config = require dirname(__DIR__) . '/sample-site/config.php';
$config['require_https'] = true;
$config['cookie_secure'] = true;
$config['hsts'] = true;
$config['hsts_max_age'] = 31536000;
$config['trusted_proxies'] = array('127.0.0.1');
return $config;
