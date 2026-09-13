<?php
/**
 * Update orchestration shared by the admin page, the API, the cron endpoint,
 * and the CLI entry. Engine internals: never reachable over HTTP.
 */
if (!defined('CMS_LOADED')) { require __DIR__ . '/engine.php'; }
require_once __DIR__ . '/modules/update-archive.php';
require_once __DIR__ . '/modules/update-installer.php';
require_once __DIR__ . '/modules/update-policy.php';
require_once __DIR__ . '/modules/update-state.php';
require_once __DIR__ . '/modules/update-transport.php';

function cms_update_enabled() {
    return cms_cfg('update_channel', 'main') !== 'off';
}

function cms_update_work_dir() {
    $configured = cms_cfg('update_work_dir');
    if (is_string($configured) && $configured !== '') { return rtrim($configured, '/\\'); }
    return cms_update_state_dir() . '/updates';
}

function cms_update_user_agent() {
    return 'Pagecore/' . cms_version() . ' (+https://github.com/taskscape/Pagecore)';
}

function cms_update_allowed_hosts() {
    return (array) cms_cfg('update_allowed_hosts', array('raw.githubusercontent.com'));
}

function cms_update_installer() {
    return new PagecoreUpdateInstaller(array(
        'site_root' => cms_cfg('site_root'),
        'state_dir' => cms_update_state_dir(),
        'work_dir' => cms_update_work_dir(),
        'apply' => (bool) cms_cfg('update_apply', false),
        'keep' => (int) cms_cfg('update_keep', 3),
        'preserve' => (array) cms_cfg('update_preserve', array('config.php')),
        'allowed_hosts' => cms_update_allowed_hosts(),
        'max_archive_bytes' => (int) cms_cfg('update_max_archive_bytes', 26214400),
        'download_timeout' => (int) cms_cfg('update_download_timeout_seconds', 120),
        'user_agent' => cms_update_user_agent(),
        'proxy' => (string) cms_cfg('update_proxy', ''),
        'ca_bundle' => (string) cms_cfg('update_ca_bundle', ''),
    ));
}

function cms_update_environment($unattended) {
    return array(
        'php_version' => PHP_VERSION,
        'channel' => cms_cfg('update_channel', 'main'),
        'allow_downgrade' => (bool) cms_cfg('update_allow_downgrade', false),
        'unattended' => (bool) $unattended,
    );
}

function cms_update_state() {
    return PagecoreUpdateState::read(cms_update_state_dir());
}

/**
 * Refresh the cached availability check. Honours the minimum interval before
 * any egress, so neither a busy admin nor a leaked cron key can amplify
 * requests to the update host.
 */
function cms_update_check($force = false, $now = null) {
    $now = $now === null ? time() : (int) $now;
    $directory = cms_update_state_dir();
    $state = PagecoreUpdateState::read($directory);
    if (!cms_update_enabled()) {
        $state['decision'] = 'disabled';
        $state['reason'] = 'Updates are switched off for this instance.';
        return $state;
    }
    if (PagecoreUpdateState::throttled($state, (int) cms_cfg('update_cron_min_interval_seconds', 300), $now)) {
        $state['throttled'] = true;
        return $state;
    }
    if (!$force && !PagecoreUpdateState::isStale($state, (int) cms_cfg('update_check_ttl_seconds', 21600), $now)) {
        return $state;
    }

    $state['last_attempt_at'] = $now;
    $transport = new PagecoreUpdateTransport(array(
        'allowed_hosts' => cms_update_allowed_hosts(),
        'timeout' => (int) cms_cfg('update_http_timeout_seconds', 20),
        'max_bytes' => 65536,
        'user_agent' => cms_update_user_agent(),
        'proxy' => (string) cms_cfg('update_proxy', ''),
        'ca_bundle' => (string) cms_cfg('update_ca_bundle', ''),
    ));
    $headers = array('Accept: application/json');
    if (!empty($state['etag'])) { $headers[] = 'If-None-Match: ' . $state['etag']; }
    $response = $transport->get((string) cms_cfg('update_manifest_url'), $headers);

    $state['http_status'] = $response->status;
    if ($response->status === 304 && is_array($state['latest'])) {
        $state['checked_at'] = $now;
        $state['error'] = null;
    } elseif (!$response->ok) {
        $state['error'] = $response->error ? $response->error : 'The update feed could not be read.';
        $state['decision'] = 'error';
        $state['reason'] = $state['error'];
    } else {
        $decoded = PagecoreJsonPolicy::decodeObject($response->body);
        $feed = $decoded->ok
            ? PagecoreUpdatePolicy::normalizeFeed($decoded->value, cms_update_allowed_hosts(), (int) cms_cfg('update_max_archive_bytes', 26214400))
            : null;
        if ($feed === null) {
            $state['error'] = 'The update feed is malformed.';
            $state['decision'] = 'malformed';
            $state['reason'] = $state['error'];
        } else {
            $state['latest'] = $feed;
            $state['etag'] = isset($response->headers['etag']) ? $response->headers['etag'] : '';
            $state['checked_at'] = $now;
            $state['error'] = null;
        }
    }

    if (is_array($state['latest']) && $state['error'] === null) {
        $outcome = PagecoreUpdatePolicy::decide(cms_build_identity(), $state['latest'], cms_update_environment(false));
        $state['decision'] = $outcome['decision'];
        $state['reason'] = $outcome['reason'];
    }
    PagecoreUpdateState::write($directory, $state);
    return $state;
}

/**
 * The single entry point behind the admin button, the cron endpoint, and the
 * CLI: refresh, decide, and apply when the instance is permitted to.
 */
function cms_update_run($unattended, $force = true) {
    if (!cms_update_enabled()) {
        return array('status' => 'disabled', 'message' => 'Updates are switched off for this instance.');
    }
    $state = cms_update_check($force);
    if (!empty($state['throttled'])) {
        return array('status' => 'throttled', 'message' => 'Checked too recently; try again later.');
    }
    if ($state['error'] !== null || !is_array($state['latest'])) {
        return array('status' => 'failed', 'message' => $state['error'] ? $state['error'] : 'No published version is known.');
    }

    $installed = cms_build_identity();
    $outcome = PagecoreUpdatePolicy::decide($installed, $state['latest'], cms_update_environment($unattended));
    $from = PagecoreUpdatePolicy::describe($installed);
    $to = PagecoreUpdatePolicy::describe($state['latest']);
    if ($outcome['decision'] !== 'available') {
        $status = $outcome['decision'] === 'up_to_date' ? 'up_to_date' : 'blocked';
        return array('status' => $status, 'message' => $outcome['reason'], 'from' => $from, 'to' => $to);
    }
    if (!cms_cfg('update_apply', false)) {
        return array('status' => 'available', 'message' => 'Version ' . $state['latest']['version'] . ' is available; update_apply is disabled.', 'from' => $from, 'to' => $to);
    }
    if ($unattended && !cms_cfg('update_auto_apply', true)) {
        return array('status' => 'available', 'message' => 'Version ' . $state['latest']['version'] . ' is available; unattended updates are disabled.', 'from' => $from, 'to' => $to);
    }

    cms_audit_event('update.apply', 'started', array('to' => $state['latest']['version'], 'unattended' => (bool) $unattended));
    $result = cms_update_installer()->apply($state['latest'], $installed);
    cms_update_record($result, $unattended);
    cms_audit_event('update.apply', $result->ok ? 'success' : 'failure', array(
        'status' => $result->status,
        'stage' => $result->stage,
        'to' => $state['latest']['version'],
    ));
    return array(
        'status' => $result->ok ? 'applied' : $result->status,
        'message' => $result->message,
        'from' => $result->from,
        'to' => $result->to,
    );
}

/** Persist the outcome so the admin page can report it after the swap. */
function cms_update_record(PagecoreUpdateOutcome $result, $unattended) {
    $directory = cms_update_state_dir();
    $state = PagecoreUpdateState::read($directory);
    $state['last_apply'] = array(
        'at' => time(),
        'from' => $result->from,
        'to' => $result->to,
        'result' => $result->ok ? 'success' : 'failed',
        'stage' => $result->stage,
        'unattended' => (bool) $unattended,
        'message' => $result->message,
    );
    if ($result->ok) {
        // The stamp the notice compares against has changed on disk.
        $state['decision'] = 'up_to_date';
        $state['reason'] = 'This instance runs the published build.';
        $state['checked_at'] = 0;
    }
    PagecoreUpdateState::write($directory, $state);
}

/** Everything the update page renders, gathered in one pass. */
function cms_update_overview() {
    $installed = cms_build_identity();
    $state = cms_update_state();
    $installer = cms_update_installer();
    $latest = is_array($state['latest']) ? $state['latest'] : null;
    $outcome = $latest === null
        ? array('decision' => $state['decision'], 'reason' => $state['reason'])
        : PagecoreUpdatePolicy::decide($installed, $latest, cms_update_environment(false));
    return array(
        'enabled' => cms_update_enabled(),
        'installed' => $installed,
        'installed_label' => PagecoreUpdatePolicy::describe($installed),
        'latest' => $latest,
        'latest_label' => $latest === null ? '' : PagecoreUpdatePolicy::describe($latest),
        'decision' => $outcome['decision'],
        'reason' => $outcome['reason'],
        'checked_at' => (int) $state['checked_at'],
        'error' => $state['error'],
        'last_apply' => $state['last_apply'],
        'checks' => $installer->preflight($latest),
        'can_apply' => (bool) cms_cfg('update_apply', false) && $installer->preflightPassed($installer->preflight($latest)),
        'snapshots' => $installer->snapshots(),
        'changes' => $latest === null ? null : cms_update_change_summary(),
    );
}

/**
 * File-level difference between the installed manifest and the published one.
 * Returns null when the installed tree carries no manifest to compare.
 */
function cms_update_change_summary() {
    $installedManifest = PagecoreUpdateArchive::readManifest(dirname(CMS_DIR));
    if ($installedManifest === null) { return null; }
    return array('files' => count($installedManifest['files']), 'version' => $installedManifest['version']);
}
