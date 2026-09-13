<?php
require __DIR__ . '/engine.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/admin-view.php';
require __DIR__ . '/update-service.php';

if (!cms_is_logged_in()) {
    $next = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : cms_admin_url('update.php');
    header('Location: ' . cms_admin_url('login.php') . '?next=' . rawurlencode($next));
    exit;
}
cms_require_no_maintenance();

$overview = cms_update_overview();

function cms_update_e($value) {
    return cms_admin_e($value);
}

function cms_update_when($timestamp) {
    return $timestamp ? gmdate('Y-m-d H:i', (int) $timestamp) . ' UTC' : 'never';
}

$decisionLabels = array(
    'available' => 'Update available',
    'up_to_date' => 'Up to date',
    'blocked_php' => 'Blocked by PHP version',
    'blocked_downgrade' => 'Blocked: not newer',
    'blocked_channel' => 'Blocked: different channel',
    'blocked_unknown_build' => 'Manual confirmation required',
    'malformed' => 'Feed unreadable',
    'error' => 'Check failed',
    'disabled' => 'Updates disabled',
    'unknown' => 'Not checked yet',
);
$decisionLabel = isset($decisionLabels[$overview['decision']]) ? $decisionLabels[$overview['decision']] : $overview['decision'];
$isAvailable = $overview['decision'] === 'available';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Updates - Pagecore CMS</title>
  <?= cms_admin_head_assets() ?>
  <style nonce="<?= cms_update_e(cms_csp_nonce()) ?>">
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--pc-bg); color: var(--pc-text); font: 14px/1.5 "Open Sans", -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
    .shell { max-width: 900px; margin: 0 auto; padding: 28px 20px 56px; }
    h1 { margin: 0; font-size: 28px; line-height: 1.15; }
    h2 { margin: 0 0 12px; font-size: 18px; }
    .sub { margin: 4px 0 0; color: var(--pc-muted); }
    .section { background: var(--pc-surface); border: 1px solid var(--pc-border); border-radius: var(--pc-radius); padding: 18px 20px; margin-bottom: 18px; }
    .identity { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
    .identity div { border: 1px solid var(--pc-border); border-radius: var(--pc-radius-sm); padding: 12px 14px; }
    .identity dt { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--pc-muted); margin-bottom: 4px; }
    .identity dd { margin: 0; font-size: 15px; font-weight: 600; word-break: break-word; }
    .state { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
    .state.ok { background: var(--pc-success-bg); color: var(--pc-success); }
    .state.info { background: var(--pc-surface-soft); color: var(--pc-muted); border: 1px solid var(--pc-border); }
    .state.warn { background: var(--pc-danger-bg); color: var(--pc-danger); }
    table { border-collapse: collapse; width: 100%; font-size: 13px; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--pc-border); vertical-align: top; }
    tbody tr:last-child td { border-bottom: 0; }
    .check-ok { color: var(--pc-success); font-weight: 600; }
    .check-no { color: var(--pc-danger); font-weight: 600; }
    .muted { color: var(--pc-muted); }
    .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 16px; }
    .button { border: 1px solid var(--pc-border-strong); background: var(--pc-surface); color: var(--pc-text); border-radius: var(--pc-radius-sm); padding: 9px 16px; font: inherit; font-weight: 600; cursor: pointer; }
    .button:hover { background: var(--pc-surface-soft); }
    .button-primary { background: var(--pc-accent); border-color: var(--pc-accent); color: #fff; }
    .button-primary:hover { background: var(--pc-accent-hover); }
    .button[disabled] { opacity: .5; cursor: not-allowed; }
    pre { background: var(--pc-surface-soft); border: 1px solid var(--pc-border); border-radius: var(--pc-radius-sm); padding: 12px; overflow-x: auto; font-size: 12px; margin: 10px 0 0; }
    .status { margin-top: 12px; font-size: 13px; }
    .status.error { color: var(--pc-danger); }
    ul.plain { margin: 0; padding-left: 18px; }
    ul.plain li { margin-bottom: 4px; }
  </style>
</head>
<body class="pc-admin">
  <div class="pc-app">
    <?= cms_admin_sidebar('update') ?>
    <div class="pc-main">
  <main class="shell">
    <div class="pc-page-head">
      <div>
        <p class="pc-eyebrow">Maintenance</p>
        <h1>Updates</h1>
        <p class="sub">Pagecore compares this installation against the published <code>main</code> build.</p>
      </div>
    </div>

    <section class="section" aria-labelledby="state-title">
      <h2 id="state-title">Status</h2>
      <p>
        <span class="state <?= $isAvailable ? 'ok' : ($overview['decision'] === 'up_to_date' ? 'info' : 'warn') ?>"><?= cms_update_e($decisionLabel) ?></span>
        <span class="muted"><?= cms_update_e($overview['reason']) ?></span>
      </p>
      <dl class="identity">
        <div>
          <dt>Installed</dt>
          <dd><?= cms_update_e($overview['installed_label']) ?></dd>
        </div>
        <div>
          <dt>Published</dt>
          <dd><?= cms_update_e($overview['latest_label'] !== '' ? $overview['latest_label'] : 'unknown') ?></dd>
        </div>
        <div>
          <dt>Last checked</dt>
          <dd><?= cms_update_e(cms_update_when($overview['checked_at'])) ?></dd>
        </div>
      </dl>
      <?php if ($overview['latest'] && !empty($overview['latest']['notes_url'])): ?>
        <p style="margin-top:14px"><a href="<?= cms_update_e($overview['latest']['notes_url']) ?>" rel="noopener noreferrer" target="_blank">Review the commits in this update</a></p>
      <?php endif; ?>
      <?php if ($overview['error']): ?>
        <p class="status error"><?= cms_update_e($overview['error']) ?></p>
      <?php endif; ?>
      <div class="actions">
        <button type="button" class="button" id="check-now">Check now</button>
        <?php if ($isAvailable && $overview['can_apply']): ?>
          <button type="button" class="button button-primary" id="apply-update">Update to <?= cms_update_e($overview['latest']['version']) ?></button>
        <?php elseif ($isAvailable): ?>
          <span class="muted">Applying updates is disabled on this instance.</span>
        <?php endif; ?>
      </div>
      <div class="status" id="update-status" role="status" aria-live="polite"></div>
    </section>

    <section class="section" aria-labelledby="checks-title">
      <h2 id="checks-title">Preconditions</h2>
      <table>
        <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($overview['checks'] as $check): ?>
          <tr>
            <td><?= cms_update_e($check['label']) ?></td>
            <td class="<?= $check['ok'] ? 'check-ok' : 'check-no' ?>"><?= $check['ok'] ? 'pass' : 'fail' ?></td>
            <td class="muted"><?= cms_update_e($check['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <section class="section" aria-labelledby="scope-title">
      <h2 id="scope-title">What an update changes</h2>
      <p class="muted">Only the engine directory <code>cms/</code> is replaced. These are never touched:</p>
      <ul class="plain">
        <li>Content in <code><?= cms_update_e(cms_cfg('content_dir')) ?></code></li>
        <li>Uploads in <code><?= cms_update_e(cms_cfg('uploads_dir')) ?></code></li>
        <li>Backups in <code><?= cms_update_e(cms_cfg('backup_dir')) ?></code></li>
        <li>Your configuration, and every site template outside <code>cms/</code></li>
      </ul>
      <p class="muted" style="margin-top:12px">Public pages keep serving throughout. The admin panel answers 503 for a few seconds while the directory is swapped, and the previous engine is kept as a rollback snapshot.</p>
      <?php if (!$overview['can_apply'] && $isAvailable): ?>
        <p class="muted" style="margin-top:12px">To update this installation by hand, download the published archive, verify it, and replace <code>cms/</code>:</p>
        <pre><code>curl -fLO <?= cms_update_e($overview['latest']['archive_url']) ?>

echo "<?= cms_update_e($overview['latest']['archive_sha256']) ?>  <?= cms_update_e(basename((string) parse_url($overview['latest']['archive_url'], PHP_URL_PATH))) ?>" | sha256sum -c
unzip -q <?= cms_update_e(basename((string) parse_url($overview['latest']['archive_url'], PHP_URL_PATH))) ?> -d pagecore-new
mv cms cms.old && mv pagecore-new/cms cms</code></pre>
      <?php endif; ?>
    </section>

    <?php if ($overview['snapshots']): ?>
    <section class="section" aria-labelledby="rollback-title">
      <h2 id="rollback-title">Rollback snapshots</h2>
      <table>
        <thead><tr><th>Version</th><th>Created</th><th>Path</th></tr></thead>
        <tbody>
        <?php foreach ($overview['snapshots'] as $snapshot): ?>
          <tr>
            <td><?= cms_update_e($snapshot['version']) ?></td>
            <td><?= cms_update_e(cms_update_when($snapshot['created'])) ?></td>
            <td class="muted"><?= cms_update_e($snapshot['path']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted" style="margin-top:12px">Restore one by replacing <code>cms/</code> with the snapshot directory over SSH or the file manager.</p>
    </section>
    <?php endif; ?>

    <?php if (is_array($overview['last_apply'])): ?>
    <section class="section" aria-labelledby="history-title">
      <h2 id="history-title">Last update attempt</h2>
      <p>
        <span class="state <?= $overview['last_apply']['result'] === 'success' ? 'ok' : 'warn' ?>"><?= cms_update_e($overview['last_apply']['result']) ?></span>
        <span class="muted"><?= cms_update_e(cms_update_when($overview['last_apply']['at'])) ?> · <?= cms_update_e($overview['last_apply']['message']) ?></span>
      </p>
    </section>
    <?php endif; ?>

  </main>
    </div>
  </div>

  <?= cms_admin_client_assets('PAGECORE_UPDATE', array('api' => cms_admin_url('api.php'), 'login' => cms_admin_url('login.php'), 'token' => cms_csrf_token())) ?>
  <script nonce="<?= cms_update_e(cms_csp_nonce()) ?>">
  (function () {
    'use strict';
    var cfg = window.PAGECORE_UPDATE || {};
    var client = window.PagecoreAdminClient.create(cfg);
    var status = document.getElementById('update-status');
    var check = document.getElementById('check-now');
    var apply = document.getElementById('apply-update');

    function setStatus(text, error) {
      status.className = error ? 'status error' : 'status';
      status.textContent = text || '';
    }

    check.addEventListener('click', function () {
      check.disabled = true;
      setStatus('Checking for updates...');
      client.get('update-status', { refresh: '1' })
        .then(function (res) {
          setStatus(res.reason || 'Check complete.');
          window.location.reload();
        })
        .catch(function (err) { setStatus(err.message, true); })
        .then(function () { check.disabled = false; });
    });

    if (apply) {
      apply.addEventListener('click', function () {
        if (!window.confirm('Replace the Pagecore engine with the published build? Content, uploads, and configuration are not affected.')) { return; }
        apply.disabled = true;
        if (check) { check.disabled = true; }
        setStatus('Updating. This can take a minute; do not close this page.');
        client.post('update-apply', {})
          .then(function (res) {
            setStatus(res.message || 'Update complete.');
            window.setTimeout(function () { window.location.reload(); }, 1500);
          })
          .catch(function (err) {
            setStatus(err.message, true);
            apply.disabled = false;
            if (check) { check.disabled = false; }
          });
      });
    }
  })();
  </script>
</body>
</html>
