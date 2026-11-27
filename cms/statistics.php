<?php
require __DIR__ . '/engine.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/admin-view.php';

header('Cache-Control: private, no-store');
if (!cms_is_logged_in()) {
    $next = $_SERVER['REQUEST_URI'] ?? cms_admin_url('statistics.php');
    header('Location: ' . cms_admin_url('login.php') . '?next=' . rawurlencode($next));
    exit;
}
cms_require_no_maintenance();
session_write_close();

$periods = array('day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly');
$period = $_GET['period'] ?? 'day';
if (!is_string($period) || !isset($periods[$period])) { $period = 'day'; }
$today = PagecoreTimePolicy::now(cms_cfg('timezone'))->setTime(0, 0);
$oldest = PagecoreStatistics::oldestDay($today);
$requested = $_GET['date'] ?? '';
$date = is_string($requested) && preg_match('~\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z~', $requested) === 1
    ? PagecoreTimePolicy::parsePostDate($requested, cms_cfg('timezone')) : null;
if (!$date || $date->format('Y-m-d') !== $requested || $date > $today) { $date = $today; }
if ($date < $oldest) { $date = $oldest; }
[$start, $end] = PagecoreStatistics::period($date, $period);
$report = array(
    'site' => array('new_readers' => 0, 'returning_readers' => 0, 'total_readers' => 0),
    'content' => array(),
);
$message = '';
if (!cms_cfg('statistics_enabled')) {
    $message = 'Statistics are disabled. Set statistics_enabled to true in the site configuration and add the tracker to the public templates you want to measure.';
} else {
    try {
        $report = cms_statistics_report($start->format('Y-m-d'), $end->format('Y-m-d'), $oldest->format('Y-m-d'));
    } catch (Throwable $error) {
        error_log('Pagecore statistics report failed: ' . $error->getMessage());
        http_response_code(503);
        $message = 'Statistics are temporarily unavailable. Check the server error log.';
    }
}

$metrics = array('new_readers' => 'New', 'returning_readers' => 'Returning', 'total_readers' => 'Total');
$visibleEnd = min($end->modify('-1 day'), $today);
$visibleStart = max($start, $oldest);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Statistics | Pagecore</title>
  <?= cms_admin_head_assets() ?>
</head>
<body class="pc-admin">
  <div class="pc-app">
    <?= cms_admin_sidebar('statistics') ?>
    <main class="pc-main">
      <header class="pc-page-head">
        <div>
          <h1>Statistics</h1>
          <p class="sub">Engaged readers across tracked posts and pages.</p>
        </div>
      </header>
      <div class="pc-content pc-statistics">
        <?php if ($message !== ''): ?>
          <p role="status"><?= cms_admin_e($message) ?></p>
        <?php else: ?>
          <form class="pc-statistics-filters" method="get" action="<?= cms_admin_e(cms_admin_url('statistics.php')) ?>">
            <div class="pc-statistics-field">
              <label for="statistics-period">Period</label>
              <select id="statistics-period" name="period">
                <?php foreach ($periods as $key => $label): ?>
                  <option value="<?= cms_admin_e($key) ?>"<?= $period === $key ? ' selected' : '' ?>><?= cms_admin_e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="pc-statistics-field">
              <label for="statistics-date">Date in period</label>
              <input id="statistics-date" type="date" name="date" value="<?= $date->format('Y-m-d') ?>"
                min="<?= $oldest->format('Y-m-d') ?>" max="<?= $today->format('Y-m-d') ?>" required>
            </div>
            <button class="button button-primary" type="submit">View statistics</button>
          </form>
          <p class="pc-statistics-range">
            <?= $visibleStart->format('M j, Y') ?><?= $visibleEnd > $visibleStart ? ' – ' . $visibleEnd->format('M j, Y') : '' ?>
            (<?= cms_admin_e(cms_cfg('timezone')) ?>)
          </p>
          <section aria-labelledby="site-readers">
            <h2 id="site-readers">Site readers</h2>
            <div class="summary">
              <?php foreach ($metrics as $key => $label): ?>
                <div><strong><?= number_format((int) $report['site'][$key]) ?></strong><span><?= cms_admin_e($label) ?></span></div>
              <?php endforeach; ?>
            </div>
            <p>New readers first qualified on this site during the period. Returning readers first qualified in an earlier period. Each browser counts once in the site total and once for each post or page it read.</p>
          </section>
          <?php foreach (array('post' => 'Posts', 'page' => 'Pages') as $kind => $label): ?>
            <?php $rows = array_filter($report['content'], static fn ($row) => $row['kind'] === $kind); ?>
            <section class="section" aria-labelledby="readers-<?= $kind ?>">
              <header class="section-head"><h2 id="readers-<?= $kind ?>"><?= cms_admin_e($label) ?></h2></header>
              <?php if (!$rows): ?>
                <p class="pc-statistics-empty">No engaged readers for this period.</p>
              <?php else: ?>
                <div class="pc-statistics-table">
                  <table aria-labelledby="readers-<?= $kind ?>">
                    <thead>
                      <tr>
                        <th scope="col"><?= $kind === 'post' ? 'Post' : 'Page' ?></th>
                        <?php foreach ($metrics as $metric): ?>
                          <th scope="col"><?= cms_admin_e($metric) ?></th>
                        <?php endforeach; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($rows as $row): ?>
                        <tr>
                          <td><?= cms_admin_e($row['title']) ?><small><?= cms_admin_e($row['content_key']) ?></small></td>
                          <?php foreach ($metrics as $key => $metric): ?>
                            <td><?= number_format((int) $row[$key]) ?></td>
                          <?php endforeach; ?>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </main>
  </div>
  <?= cms_admin_update_refresh() ?>
</body>
</html>
