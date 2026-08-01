<?php
require dirname(__DIR__) . '/cms/modules/Routes.php';
require dirname(__DIR__) . '/cms/config-schema.php';

$failures = array();
function route_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

route_check(PagecoreRoutes::join('/', 'cms/api.php') === '/cms/api.php', 'Root install route was not generated correctly.');
route_check(PagecoreRoutes::join('/example/site/', '/cms/api.php?action=get') === '/example/site/cms/api.php?action=get', 'Subdirectory route was not generated correctly.');
route_check(PagecoreRoutes::normalizePrefix('/example//site/') === '/example/site', 'Route prefix was not normalized.');
route_check(PagecoreRoutes::normalizePrefix('https://example.test') === null, 'Absolute route prefix was accepted.');
route_check(!PagecoreRoutes::isLocalRoute('/safe/../private'), 'Traversal sitemap route was accepted.');
route_check(!PagecoreRoutes::isLocalRoute('//outside.test/path'), 'Protocol-relative sitemap route was accepted.');
route_check(PagecoreRoutes::post('/news/', 'hello-world') === '/news/hello-world/', 'Missing post placeholder was not repaired.');
route_check(PagecoreRoutes::post('/news/{slug}/', 'hello world') === '/news/hello%20world/', 'Post slug was not encoded consistently.');

$sample = require dirname(__DIR__) . '/sample-site/config.php';
$invalid = $sample;
$invalid['sitemap_extra_routes'] = array('https://outside.test/');
list(, $errors) = cms_validate_config($invalid, false);
route_check(strpos(implode('; ', $errors), 'sitemap_extra_routes') !== false, 'Invalid sitemap route was not rejected.');
$legacy = $sample;
$legacy['post_url'] = '/sample-site/article/';
list($legacy, $legacyErrors) = cms_validate_config($legacy, false);
route_check($legacyErrors === array() && $legacy['post_url'] === '/sample-site/article/{slug}/', 'Legacy post URL pattern was not normalized.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Route policy checks passed.\n";
