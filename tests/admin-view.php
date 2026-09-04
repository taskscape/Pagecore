<?php
function cms_admin_url($path = '') { return '/control' . ($path !== '' ? '/' . ltrim($path, '/') : ''); }
function cms_site_url($path = '') { return '/site' . ($path !== '' ? '/' . ltrim($path, '/') : ''); }
function cms_version() { return 'test-version'; }
function cms_asset_url($filename) { return cms_admin_url('assets/' . ltrim($filename, '/')) . '?v=' . rawurlencode(cms_version()); }
function cms_csp_nonce() { return 'test-nonce'; }
function cms_csrf_token() { return 'test-token'; }
// The notice and the background refresh are driven entirely by cached state,
// which the engine supplies; stub both so the view is tested on its own.
function cms_update_notice() { return isset($GLOBALS['TEST_UPDATE_NOTICE']) ? $GLOBALS['TEST_UPDATE_NOTICE'] : null; }
function cms_update_check_due() { return !empty($GLOBALS['TEST_UPDATE_DUE']); }
require dirname(__DIR__) . '/cms/admin-view.php';

$failures = array();
function admin_view_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

admin_view_check(cms_admin_e('"<script>') === '&quot;&lt;script&gt;', 'Shared escaping policy changed.');
$head = cms_admin_head_assets();
admin_view_check(substr_count($head, 'fonts.googleapis.com') === 2, 'Shared font request was not consolidated.');
admin_view_check(strpos($head, '/control/assets/admin.css?v=test-version') !== false, 'Configured versioned admin asset route is missing.');
$content = cms_admin_sidebar('content');
admin_view_check(strpos($content, 'href="/control/content.php" aria-current="page"') !== false, 'Content navigation does not expose its current page.');
admin_view_check(strpos($content, 'Version test-version') !== false, 'Content shell version is missing.');
$media = cms_admin_sidebar('media', false);
admin_view_check(strpos($media, '/control/media.php?picker=1') !== false, 'Media picker route is missing.');
$bootstrap = cms_admin_client_assets('PAGECORE_TEST', array('api' => '/control/api.php', 'token' => '<token>'));
admin_view_check(strpos($bootstrap, 'window.PAGECORE_TEST') !== false && strpos($bootstrap, 'admin-client.js') !== false, 'Shared client bootstrap is incomplete.');
admin_view_check(substr_count($bootstrap, '?v=test-version') === 2, 'Shared client assets are not versioned consistently.');

/* ------------------------------------------------------------ update notice */
// Nothing is shown while the instance is current.
admin_view_check(cms_admin_update_notice() === '', 'A notice was rendered without a published update.');
admin_view_check(strpos(cms_admin_sidebar('content'), 'pc-update-notice') === false, 'The sidebar showed a notice without a published update.');

$GLOBALS['TEST_UPDATE_NOTICE'] = array('version' => '2.51.0', 'identity' => '2.51.0 (9f1c2ab)', 'url' => '/control/update.php');
$notice = cms_admin_update_notice();
admin_view_check(strpos($notice, 'Update available: 2.51.0') !== false, 'The notice does not name the published version.');
admin_view_check(strpos($notice, 'href="/control/update.php"') !== false, 'The notice does not link to the update page.');
admin_view_check(strpos($notice, 'role="status"') !== false, 'The notice is not announced to assistive technology.');
admin_view_check(strpos(cms_admin_sidebar('media'), 'Update available: 2.51.0') !== false, 'The media shell hides the update notice.');
admin_view_check(strpos(cms_admin_sidebar('content'), '/control/update.php') !== false, 'The update page is not reachable from the navigation.');

$GLOBALS['TEST_UPDATE_NOTICE'] = array('version' => '<script>', 'identity' => 'x', 'url' => '/control/update.php');
admin_view_check(strpos(cms_admin_update_notice(), '<script>') === false, 'The notice did not escape a hostile version string.');
$GLOBALS['TEST_UPDATE_NOTICE'] = null;

/* --------------------------------------------------- background refresh hook */
admin_view_check(cms_admin_update_refresh() === '', 'A refresh was scheduled while the cached check was fresh.');
$GLOBALS['TEST_UPDATE_DUE'] = true;
$refresh = cms_admin_update_refresh();
admin_view_check(strpos($refresh, 'action=update-status&refresh=1') !== false, 'The refresh does not call the status action.');
admin_view_check(strpos($refresh, 'nonce="test-nonce"') !== false, 'The refresh script is not nonce-bound for the CSP.');
// Same-origin only: connect-src stays 'self' and the browser never sees GitHub.
admin_view_check(strpos($refresh, 'https://') === false, 'The refresh contacts a third-party origin from the browser.');
$GLOBALS['TEST_UPDATE_DUE'] = false;

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Admin view checks passed.\n";
