<?php
function cms_admin_url($path = '') { return '/control' . ($path !== '' ? '/' . ltrim($path, '/') : ''); }
function cms_site_url($path = '') { return '/site' . ($path !== '' ? '/' . ltrim($path, '/') : ''); }
function cms_version() { return 'test-version'; }
function cms_csp_nonce() { return 'test-nonce'; }
require dirname(__DIR__) . '/cms/admin-view.php';

$failures = array();
function admin_view_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

admin_view_check(cms_admin_e('"<script>') === '&quot;&lt;script&gt;', 'Shared escaping policy changed.');
$head = cms_admin_head_assets();
admin_view_check(substr_count($head, 'fonts.googleapis.com') === 2, 'Shared font request was not consolidated.');
admin_view_check(strpos($head, '/control/assets/admin.css') !== false, 'Configured admin asset route is missing.');
$content = cms_admin_sidebar('content');
admin_view_check(strpos($content, 'href="/control/content.php" aria-current="page"') !== false, 'Content navigation does not expose its current page.');
admin_view_check(strpos($content, 'Version test-version') !== false, 'Content shell version is missing.');
$media = cms_admin_sidebar('media', false);
admin_view_check(strpos($media, '/control/media.php?picker=1') !== false, 'Media picker route is missing.');
$bootstrap = cms_admin_client_assets('PAGECORE_TEST', array('api' => '/control/api.php', 'token' => '<token>'));
admin_view_check(strpos($bootstrap, 'window.PAGECORE_TEST') !== false && strpos($bootstrap, 'admin-client.js') !== false, 'Shared client bootstrap is incomplete.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Admin view checks passed.\n";
