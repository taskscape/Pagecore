<?php

require dirname(__DIR__) . '/cms/modules/update-policy.php';

$failures = array();
function policy_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$hosts = array('raw.githubusercontent.com', 'github.com', 'objects.githubusercontent.com');
$installedCommit = str_repeat('a', 40);
$publishedCommit = str_repeat('b', 40);

/* --------------------------------------------------------------- primitives */
policy_check(PagecoreUpdatePolicy::isCommit($installedCommit), 'A full lowercase SHA was rejected.');
policy_check(!PagecoreUpdatePolicy::isCommit(strtoupper($installedCommit)), 'An uppercase SHA was accepted.');
policy_check(!PagecoreUpdatePolicy::isCommit(substr($installedCommit, 0, 7)), 'A short SHA was accepted.');
policy_check(PagecoreUpdatePolicy::instant('2026-09-04T06:22:11Z') === 1788502931, 'A Zulu instant was misparsed.');
policy_check(PagecoreUpdatePolicy::instant('2026-09-04T08:22:11+02:00') === 1788502931, 'An offset instant was misparsed.');
policy_check(PagecoreUpdatePolicy::instant('2026-09-04 06:22:11') === null, 'A non RFC 3339 instant was accepted.');
policy_check(PagecoreUpdatePolicy::instant('2026-13-04T06:22:11Z') === null, 'An out-of-range month was accepted.');

/* ----------------------------------------------------------- host allowlist */
policy_check(PagecoreUpdatePolicy::allowedHost('https://github.com/a.zip', $hosts) === 'github.com', 'An allowlisted host was rejected.');
policy_check(PagecoreUpdatePolicy::allowedHost('http://github.com/a.zip', $hosts) === null, 'Plain HTTP was accepted.');
policy_check(PagecoreUpdatePolicy::allowedHost('https://evil.test/a.zip', $hosts) === null, 'An unlisted host was accepted.');
policy_check(PagecoreUpdatePolicy::allowedHost('https://github.com.evil.test/a.zip', $hosts) === null, 'A suffix-matched host was accepted.');
policy_check(PagecoreUpdatePolicy::allowedHost('https://user:pw@github.com/a.zip', $hosts) === null, 'A URL carrying credentials was accepted.');
policy_check(PagecoreUpdatePolicy::allowedHost('https://GITHUB.com/a.zip', $hosts) === 'github.com', 'Host comparison was case sensitive.');

/* ------------------------------------------------------------- feed parsing */
function policy_feed(array $overrides = array()) {
    return array_merge(array(
        'schema' => 1,
        'channel' => 'main',
        'version' => '2.50.1',
        'commit' => str_repeat('b', 40),
        'commit_time' => '2026-09-06T11:02:44Z',
        'archive_url' => 'https://github.com/taskscape/Pagecore/releases/download/build-bbbbbbb/pagecore.zip',
        'archive_sha256' => str_repeat('c', 64),
        'archive_bytes' => 412873,
        'min_php' => '8.3.0',
        'notes_url' => 'https://github.com/taskscape/Pagecore/compare/a...b',
    ), $overrides);
}

policy_check(PagecoreUpdatePolicy::normalizeFeed(policy_feed(), $hosts) !== null, 'A valid feed was rejected.');
policy_check(PagecoreUpdatePolicy::normalizeFeed(policy_feed(array('schema' => 2)), $hosts) === null, 'An unknown feed schema was accepted.');
policy_check(PagecoreUpdatePolicy::normalizeFeed(policy_feed(array('archive_url' => 'https://evil.test/x.zip')), $hosts) === null, 'A feed pointing off the allowlist was accepted.');
policy_check(PagecoreUpdatePolicy::normalizeFeed(policy_feed(array('archive_sha256' => 'nope')), $hosts) === null, 'A feed with a malformed digest was accepted.');
policy_check(PagecoreUpdatePolicy::normalizeFeed(policy_feed(array('archive_bytes' => 12)), $hosts) === null, 'An implausibly small archive was accepted.');
policy_check(PagecoreUpdatePolicy::normalizeFeed(policy_feed(array('archive_bytes' => 99999999)), $hosts, 26214400) === null, 'An oversized archive was accepted.');
policy_check(PagecoreUpdatePolicy::normalizeFeed(policy_feed(array('version' => '2.50')), $hosts) === null, 'A malformed version was accepted.');
policy_check(PagecoreUpdatePolicy::normalizeFeed(policy_feed(array('commit' => 'zz')), $hosts) === null, 'A malformed commit was accepted.');
$offListNotes = PagecoreUpdatePolicy::normalizeFeed(policy_feed(array('notes_url' => 'https://evil.test/notes')), $hosts);
policy_check($offListNotes !== null && $offListNotes['notes_url'] === null, 'An off-allowlist notes URL was carried through.');

/* ------------------------------------------------------------ build stamps */
$stamp = array('schema' => 1, 'version' => '2.49.0', 'commit' => $installedCommit, 'commit_time' => '2026-09-04T06:22:11Z', 'channel' => 'main');
$installed = PagecoreUpdatePolicy::normalizeBuild($stamp);
policy_check($installed !== null && $installed['commit'] === $installedCommit, 'A valid build stamp was rejected.');
policy_check(PagecoreUpdatePolicy::normalizeBuild(array_merge($stamp, array('commit' => 'short'))) === null, 'A malformed stamp commit was accepted.');
policy_check(PagecoreUpdatePolicy::normalizeBuild(array_merge($stamp, array('channel' => 'nightly'))) === null, 'An unknown stamp channel was accepted.');

/* --------------------------------------------------------- decision table */
$feed = PagecoreUpdatePolicy::normalizeFeed(policy_feed(), $hosts);
$interactive = array('php_version' => '8.4.0', 'channel' => 'main', 'allow_downgrade' => false, 'unattended' => false);
$unattended = array_merge($interactive, array('unattended' => true));

$decision = PagecoreUpdatePolicy::decide($installed, $feed, $interactive);
policy_check($decision['decision'] === 'available', 'A newer published build was not offered.');

$same = PagecoreUpdatePolicy::normalizeBuild(array_merge($stamp, array('version' => '2.50.1', 'commit' => $publishedCommit, 'commit_time' => '2026-09-06T11:02:44Z')));
policy_check(PagecoreUpdatePolicy::decide($same, $feed, $interactive)['decision'] === 'up_to_date', 'The installed build was not recognised as current.');

// Anti-rollback: an older tip must never be installable, whatever its version.
$newer = PagecoreUpdatePolicy::normalizeBuild(array_merge($stamp, array('commit_time' => '2026-09-08T00:00:00Z')));
policy_check(PagecoreUpdatePolicy::decide($newer, $feed, $interactive)['decision'] === 'blocked_downgrade', 'A stale feed was allowed to roll the instance back.');
$equalTime = PagecoreUpdatePolicy::normalizeBuild(array_merge($stamp, array('commit_time' => '2026-09-06T11:02:44Z')));
policy_check(PagecoreUpdatePolicy::decide($equalTime, $feed, $interactive)['decision'] === 'blocked_downgrade', 'An equal commit time was treated as newer.');

$olderVersionFeed = PagecoreUpdatePolicy::normalizeFeed(policy_feed(array('version' => '2.48.0')), $hosts);
policy_check(PagecoreUpdatePolicy::decide($installed, $olderVersionFeed, $interactive)['decision'] === 'blocked_downgrade', 'A version downgrade was accepted.');
policy_check(PagecoreUpdatePolicy::decide($installed, $olderVersionFeed, array_merge($interactive, array('allow_downgrade' => true)))['decision'] === 'available', 'An explicitly allowed downgrade was refused.');

policy_check(PagecoreUpdatePolicy::decide($installed, $feed, array_merge($interactive, array('php_version' => '8.2.9')))['decision'] === 'blocked_php', 'An unsupported PHP version was not blocked.');
policy_check(PagecoreUpdatePolicy::decide($installed, $feed, array_merge($interactive, array('channel' => 'off')))['decision'] === 'blocked_channel', 'A channel mismatch was not blocked.');
policy_check(PagecoreUpdatePolicy::decide($installed, null, $interactive)['decision'] === 'malformed', 'A missing feed was not reported as malformed.');

// An unstamped install may be updated by hand, but never unattended.
$unstamped = PagecoreUpdatePolicy::unstampedBuild('2.49.0');
policy_check(PagecoreUpdatePolicy::decide($unstamped, $feed, $interactive)['decision'] === 'available', 'An unstamped install was not offered an interactive update.');
policy_check(PagecoreUpdatePolicy::decide($unstamped, $feed, $unattended)['decision'] === 'blocked_unknown_build', 'An unstamped install was updated unattended.');
policy_check(PagecoreUpdatePolicy::decide($unstamped, $olderVersionFeed, $interactive)['decision'] === 'blocked_downgrade', 'An unstamped install accepted an older version.');

/* ---------------------------------------------------------------- identity */
policy_check(PagecoreUpdatePolicy::describe($installed) === '2.49.0 (' . substr($installedCommit, 0, 7) . ' · 2026-09-04)', 'The identity label was not formatted as expected.');
policy_check(PagecoreUpdatePolicy::describe($unstamped) === '2.49.0', 'An unstamped identity leaked placeholder detail.');
policy_check(PagecoreUpdatePolicy::buildId($installed) === '2.49.0-' . substr($installedCommit, 0, 12), 'The asset build id did not follow the commit.');
policy_check(PagecoreUpdatePolicy::buildId($unstamped) === '2.49.0', 'The unstamped asset build id changed shape.');

/* -------------------------------------------------------------- cron keys */
$key = str_repeat('k', 40);
policy_check(PagecoreUpdatePolicy::cronKeyConfigured($key), 'A long key was treated as unusable.');
policy_check(!PagecoreUpdatePolicy::cronKeyConfigured(''), 'An empty key was treated as usable.');
policy_check(!PagecoreUpdatePolicy::cronKeyConfigured(str_repeat('k', 31)), 'A short key was treated as usable.');
policy_check(!PagecoreUpdatePolicy::cronKeyConfigured('REPLACE_WITH_UPDATE_CRON_KEY_PLACEHOLDER'), 'The example placeholder was treated as usable.');
policy_check(PagecoreUpdatePolicy::cronKeyAccepted($key, $key), 'A matching key was rejected.');
policy_check(!PagecoreUpdatePolicy::cronKeyAccepted($key, $key . 'x'), 'A longer key was accepted.');
policy_check(!PagecoreUpdatePolicy::cronKeyAccepted($key, substr($key, 0, 39)), 'A truncated key was accepted.');
policy_check(!PagecoreUpdatePolicy::cronKeyAccepted('', ''), 'An empty configured key accepted an empty request key.');
policy_check(!PagecoreUpdatePolicy::cronKeyAccepted($key, ''), 'A missing request key was accepted.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
fwrite(STDOUT, "PASS: update policy blocks downgrades, replays, off-allowlist hosts, and unattended unstamped builds\n");
