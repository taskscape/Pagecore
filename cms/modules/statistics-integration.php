<?php

require_once __DIR__ . '/statistics.php';

function cms_statistics_cookie_name(): string {
    return cms_cfg('session_name') . '_READER';
}

// Use a stable template key for pages and the canonical Markdown slug for posts.
function cms_statistics_tracker(string $kind, string $key, string $title, string $selector = 'main'): string {
    if (!cms_cfg('statistics_enabled') || cms_is_logged_in()
        || session_status() !== PHP_SESSION_ACTIVE || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
        || http_response_code() >= 300 || PagecoreStatistics::isBot($_SERVER['HTTP_USER_AGENT'] ?? '')
        || PagecoreStatistics::sidecar($kind, $key) === null) {
        return '';
    }

    $secret = $_SESSION['cms_statistics_secret'] ??= bin2hex(random_bytes(32));
    $payload = base64_encode(json_encode(array(
        'kind' => $kind,
        'key' => $key,
        'title' => substr(trim($title), 0, 255) ?: $key,
        'issued' => time(),
    ), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    $ticket = $payload . '.' . hash_hmac('sha256', $payload, $secret);

    return '<script src="' . htmlspecialchars(cms_asset_url('statistics.js'), ENT_QUOTES, 'UTF-8') . '" defer'
        . ' data-pagecore-read-url="' . htmlspecialchars(cms_admin_url('statistics-read.php'), ENT_QUOTES, 'UTF-8') . '"'
        . ' data-ticket="' . htmlspecialchars($ticket, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-content="' . htmlspecialchars($selector, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-seconds="' . PagecoreStatistics::MIN_VISIBLE_SECONDS . '"></script>';
}

function cms_statistics_read_ticket(string $ticket, string $secret, int $now): ?array {
    $parts = explode('.', $ticket, 2);
    if ($secret === '' || count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], $secret), $parts[1])) {
        return null;
    }
    $payload = base64_decode($parts[0], true);
    $target = $payload !== false ? json_decode($payload, true) : null;
    if (!is_array($target) || !isset($target['issued'])) { return null; }
    $age = $now - $target['issued'];
    return $age < PagecoreStatistics::MIN_VISIBLE_SECONDS || $age > 86400 ? null : $target;
}

function cms_statistics_public_post(string $key): bool {
    $path = cms_post_path($key, true);
    $markdown = $path ? @file_get_contents($path) : false;
    if ($markdown === false) { return false; }
    $parsed = cms_parse_front_matter_detailed($markdown);
    return !$parsed['diagnostics'] && cms_post_status_is_public($parsed['meta']['status'] ?? 'publish');
}

function cms_statistics_path(string $relative): string {
    $root = cms_cfg('content_dir');
    $file = PagecorePathPolicy::resolveWithin($root, $relative, false);
    if ($file === null || is_link($root . '/' . $relative)) {
        throw new RuntimeException('Invalid statistics storage path.');
    }
    return $file;
}

function cms_statistics_check_rate(string $source, int $minute): bool {
    return cms_with_resource_lock('statistics-rate', static function () use ($source, $minute): bool {
        $file = cms_statistics_path('.state/statistics-rate.json');
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR) : array();
        if (($state['minute'] ?? 0) > $minute) { return false; }
        $counts = ($state['minute'] ?? null) === $minute ? $state['counts'] : array();
        if (($counts[$source] ?? 0) >= 120 || (!isset($counts[$source]) && count($counts) >= 4096)) { return false; }
        $counts[$source] = ($counts[$source] ?? 0) + 1;
        $next = json_encode(array('minute' => $minute, 'counts' => $counts), JSON_THROW_ON_ERROR);
        if (!cms_atomic_write($file, $next . "\n")) { throw new RuntimeException('Could not update the statistics rate limit.'); }
        return true;
    });
}

function cms_statistics_record(string $relative, array $entry, DateTimeImmutable $now): void {
    cms_with_resource_lock('statistics:' . $relative, static function () use ($relative, $entry, $now): void {
        $file = cms_statistics_path($relative);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the statistics directory.');
        }
        clearstatcache(true, $file);
        $modified = is_file($file) ? filemtime($file) : false;
        // Compact an active sidecar on the first qualifying read of a new
        // month. Keep one entry per reader and day within the reporting window.
        if ($modified !== false && PagecoreTimePolicy::formatEpoch($modified, 'Y-m', $now->getTimezone()->getName()) !== $now->format('Y-m')) {
            $oldest = PagecoreStatistics::oldestDay($now)->format('Y-m-d');
            $kept = array();
            foreach (PagecoreStatistics::entries($file) as $previous) {
                if ($previous[0] >= $oldest) { $kept[$previous[0] . $previous[1]] = PagecoreStatistics::line($previous); }
            }
            $kept[$entry[0] . $entry[1]] = PagecoreStatistics::line($entry);
            if (!cms_atomic_write($file, implode('', $kept))) {
                throw new RuntimeException('Could not compact the statistics sidecar.');
            }
            return;
        }

        $handle = fopen($file, 'c+b');
        if ($handle === false) { throw new RuntimeException('Could not open the statistics sidecar for writing.'); }
        try {
            if (fseek($handle, 0, SEEK_END) !== 0 || ($size = ftell($handle)) === false) {
                throw new RuntimeException('Could not locate the end of the statistics sidecar.');
            }
            $line = PagecoreStatistics::line($entry);
            if ($size > 0) {
                if (fseek($handle, -1, SEEK_END) !== 0 || ($last = fgetc($handle)) === false) {
                    throw new RuntimeException('Could not check the statistics sidecar.');
                }
                // A torn final record stays invalid after the next append.
                if ($last !== "\n") { $line = "?\n" . $line; }
            }
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
                ftruncate($handle, $size);
                throw new RuntimeException('Could not write the statistics sidecar.');
            }
        } finally {
            fclose($handle);
        }
    });
}

function cms_statistics_targets(): Generator {
    $root = cms_cfg('content_dir');
    $posts = PagecorePathPolicy::resolveWithin($root, 'posts');
    if ($posts !== null && is_dir($posts)) {
        foreach (glob($posts . '/*.readers.jsonl') ?: array() as $file) {
            $key = basename($file, '.readers.jsonl');
            $relative = PagecoreStatistics::sidecar('post', $key);
            if ($relative !== null && !is_link($file) && is_file($file)) { yield array('post', $key, $relative); }
        }
    }
    $pages = PagecorePathPolicy::resolveWithin($root, 'pages');
    if ($pages === null || !is_dir($pages)) { return; }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pages, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getFilename() !== '.readers.jsonl' || !$file->isFile() || $file->isLink()) { continue; }
        $key = PagecorePathPolicy::relativeTo($file->getPath(), $pages);
        $relative = $key === null ? null : PagecoreStatistics::sidecar('page', $key);
        if ($relative !== null) { yield array('page', $key, $relative); }
    }
}

function cms_statistics_report(string $start, string $end, string $oldest): array {
    $site = $content = array();
    $earliest = max($oldest, $start);
    foreach (cms_statistics_targets() as [$kind, $key, $relative]) {
        [$readers, $title] = cms_with_resource_lock('statistics:' . $relative, static function () use ($relative, $earliest, $end, $key): array {
            $file = cms_statistics_path($relative);
            $readers = array();
            $title = $key;
            if (is_file($file)) {
                foreach (PagecoreStatistics::entries($file) as [$day, $reader, $firstDay, $label]) {
                    if ($day < $earliest || $day >= $end) { continue; }
                    $readers[$reader] = $firstDay;
                    $title = $label;
                }
            }
            return array($readers, $title);
        });
        if (!$readers) { continue; }
        $site += $readers;
        $content[] = array('kind' => $kind, 'content_key' => $key, 'title' => $title)
            + PagecoreStatistics::counts($readers, $start);
    }
    usort($content, static fn ($left, $right) =>
        ($right['total_readers'] <=> $left['total_readers'])
        ?: strcasecmp($left['title'], $right['title'])
        ?: strcmp($left['content_key'], $right['content_key'])
    );
    return array('site' => PagecoreStatistics::counts($site, $start), 'content' => $content);
}
