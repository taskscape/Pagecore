<?php
require dirname(__DIR__) . '/cms/modules/TimePolicy.php';

$failures = array();
function time_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

time_check(PagecoreTimePolicy::timezone('Europe/Warsaw') instanceof DateTimeZone, 'Valid IANA timezone was rejected.');
time_check(PagecoreTimePolicy::timezone('Mars/Olympus') === null, 'Invalid timezone was accepted.');
time_check(PagecoreTimePolicy::parsePostDate('2024-02-29 23:59:59', 'UTC') instanceof DateTimeImmutable, 'Valid leap-day timestamp was rejected.');
foreach (array('2023-02-29', '2026-13-01', '2026-04-31', '01-08-2026', '2026-08-01T12:00:00') as $invalid) {
    time_check(PagecoreTimePolicy::parsePostDate($invalid, 'UTC') === null, 'Impossible or unsupported date was accepted: ' . $invalid);
}
time_check(PagecoreTimePolicy::formatEpoch(1774744200, 'Y-m-d H:i T', 'UTC') === '2026-03-29 00:30 UTC', 'UTC epoch formatting changed.');
time_check(PagecoreTimePolicy::formatEpoch(1774744200, 'Y-m-d H:i T', 'Europe/Warsaw') === '2026-03-29 01:30 CET', 'Pre-DST Warsaw formatting changed.');
time_check(PagecoreTimePolicy::formatEpoch(1774747800, 'Y-m-d H:i T', 'Europe/Warsaw') === '2026-03-29 03:30 CEST', 'DST transition formatting changed.');
time_check(PagecoreTimePolicy::displayDate('2026-08-01', 'Europe/Warsaw') === '1 August 2026', 'Compatible post display date changed.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Time policy checks passed.\n";
