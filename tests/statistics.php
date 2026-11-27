<?php

require dirname(__DIR__) . '/cms/engine.php';

function statistics_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

$zone = new DateTimeZone('Europe/Warsaw');
$monday = new DateTimeImmutable('2026-11-23 23:30', $zone);
$tuesday = $monday->modify('+1 day');
$wednesday = $tuesday->modify('+1 day');
$previous = $monday->modify('-1 day');
foreach (array(
    'day' => array('2026-11-24', '2026-11-25'),
    'week' => array('2026-11-23', '2026-11-30'),
    'month' => array('2026-11-01', '2026-12-01'),
    'year' => array('2026-01-01', '2027-01-01'),
) as $period => $expected) {
    $actual = array_map(static fn ($date) => $date->format('Y-m-d'), PagecoreStatistics::period($tuesday, $period));
    statistics_check($expected === $actual, 'Incorrect calendar boundaries for ' . $period);
}
[$spring, $springEnd] = PagecoreStatistics::period(new DateTimeImmutable('2026-03-29 12:00', $zone), 'day');
statistics_check($springEnd->getTimestamp() - $spring->getTimestamp() === 23 * 3600, 'Day range ignored the DST transition.');
statistics_check(PagecoreStatistics::sidecar('page', '../private') === null, 'Page traversal was accepted.');
statistics_check(PagecoreStatistics::sidecar('post', 'nested/post') === null, 'Post traversal was accepted.');
statistics_check(PagecoreStatistics::isBot('Mozilla/5.0 (compatible; Googlebot/2.1)'), 'Googlebot was accepted.');
statistics_check(PagecoreStatistics::isBot('facebookexternalhit/1.1'), 'A link preview was accepted.');
statistics_check(!PagecoreStatistics::isBot('Mozilla/5.0 Firefox/148.0'), 'A regular browser was filtered.');

$secret = 'statistics-contract-secret';
$day = $monday->format('Y-m-d');
$next = $tuesday->format('Y-m-d');
[$cookie, $newReader, $firstDay] = PagecoreStatistics::identity('', $day, $secret);
statistics_check(PagecoreStatistics::identity($cookie, $next, $secret) === array($cookie, $newReader, $firstDay), 'Browser identity changed on return.');
[, $tamperedReader, $tamperedFirstDay] = PagecoreStatistics::identity(str_replace($day, '2025-01-01', $cookie), $next, $secret);
statistics_check($tamperedReader !== $newReader && $tamperedFirstDay === $next, 'A modified first-read date was accepted.');
[, $returningReader, $returningFirstDay] = PagecoreStatistics::identity('', $previous->format('Y-m-d'), $secret);

$root = sys_get_temp_dir() . '/pagecore-statistics-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$GLOBALS['CMS_CONFIG']['content_dir'] = $root;
try {
    $post = PagecoreStatistics::sidecar('post', 'launch-notes');
    $page = PagecoreStatistics::sidecar('page', 'about/team');
    cms_statistics_record($post, array($day, $newReader, $firstDay, 'Launch notes'), $monday);
    cms_statistics_record($page, array($day, $newReader, $firstDay, 'Team'), $monday);
    cms_statistics_record($post, array($next, $newReader, $firstDay, 'Launch notes'), $tuesday);
    cms_statistics_record($post, array($next, $newReader, $firstDay, 'Launch notes'), $tuesday);
    cms_statistics_record($post, array($next, $returningReader, $returningFirstDay, 'Launch notes'), $tuesday);
    cms_statistics_record($page, array($next, $returningReader, $returningFirstDay, 'Team'), $tuesday);
    statistics_check(is_file($root . '/posts/launch-notes.readers.jsonl'), 'Missing post sidecar.');
    statistics_check(is_file($root . '/pages/about/team/.readers.jsonl'), 'Missing page sidecar.');
    statistics_check(!str_contains(file_get_contents($root . '/' . $post), $cookie), 'A reader cookie was written to the sidecar.');

    $oldest = '2025-01-01';
    $daily = cms_statistics_report($next, $wednesday->format('Y-m-d'), $oldest);
    $weekly = cms_statistics_report($day, '2026-11-30', $oldest);
    statistics_check($daily['site'] === array('new_readers' => 0, 'returning_readers' => 2, 'total_readers' => 2), 'Incorrect daily unique site counts.');
    statistics_check($weekly['site'] === array('new_readers' => 1, 'returning_readers' => 1, 'total_readers' => 2), 'Incorrect weekly unique site counts.');
    statistics_check(count($weekly['content']) === 2 && $weekly['content'][0]['total_readers'] === 2
        && $weekly['content'][1]['total_readers'] === 2, 'Incorrect content totals.');
    statistics_check(cms_statistics_report($day, '2026-11-30', '2026-12-01')['content'] === array(), 'Expired reads appeared in a report.');

    $month = new DateTimeImmutable('2026-12-01', $zone);
    touch($root . '/' . $post, $tuesday->getTimestamp());
    cms_statistics_record($post, array('2026-12-01', $newReader, $firstDay, 'Launch notes'), $month);
    statistics_check(count(iterator_to_array(PagecoreStatistics::entries($root . '/' . $post))) === 4, 'Monthly compaction did not deduplicate the sidecar.');

    for ($i = 0; $i < 120; $i++) {
        statistics_check(cms_statistics_check_rate('test-source', 1000), 'The collector throttled before the limit.');
    }
    statistics_check(!cms_statistics_check_rate('test-source', 1000), 'The collector rate limit was not enforced.');
    statistics_check(cms_statistics_check_rate('test-source', 1001), 'The collector rate limit did not expire.');
} finally {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if ($file->isDir()) { rmdir($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($root);
}
echo "Statistics checks passed.\n";
