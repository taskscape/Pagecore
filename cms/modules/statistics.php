<?php

final class PagecoreStatistics {
    public const MIN_VISIBLE_SECONDS = 15;
    public const RETENTION_DAYS = 730;
    public const COOKIE_LIFETIME = 31536000;

    public static function period(DateTimeImmutable $date, string $period): array {
        $day = $date->setTime(0, 0);
        [$start, $duration] = match ($period) {
            'week' => array($day->modify('monday this week'), '+1 week'),
            'month' => array($day->modify('first day of this month'), '+1 month'),
            'year' => array($day->setDate((int) $day->format('Y'), 1, 1), '+1 year'),
            default => array($day, '+1 day'),
        };
        return array($start, $start->modify($duration));
    }

    public static function oldestDay(DateTimeImmutable $today): DateTimeImmutable {
        return $today->modify('-' . (self::RETENTION_DAYS - 1) . ' days')->setTime(0, 0);
    }

    public static function isBot(string $agent): bool {
        return trim($agent) === '' || preg_match(
            '~bot\b|crawler|spider|headless|facebookexternalhit|slurp|preview|curl/|wget/|python-requests|go-http-client|lighthouse|phantomjs|scrapy|semrush|ahrefs~i',
            $agent
        ) === 1;
    }

    public static function sidecar(string $kind, string $key): ?string {
        if ($kind === 'post' && strlen($key) <= 235 && trim($key) === $key && PagecoreSlugPolicy::isLegacyContentSlug($key)) {
            return 'posts/' . $key . '.readers.jsonl';
        }
        if ($kind === 'page' && strlen($key) <= 127 && preg_match('~\A[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*\z~', $key) === 1) {
            return 'pages/' . $key . '/.readers.jsonl';
        }
        return null;
    }

    public static function validDay(string $value): bool {
        return preg_match('~\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z~', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    // The first day travels with the signed site-wide reader ID. Each content
    // file can classify a reader even if the first read was on another page.
    public static function identity(string $cookie, string $day, string $secret): array {
        $parts = explode('.', $cookie);
        if (count($parts) === 3 && preg_match('~\A[a-f0-9]{32}\z~', $parts[0]) === 1
            && self::validDay($parts[1]) && $parts[1] <= $day
            && hash_equals(hash_hmac('sha256', 'reader:' . $parts[0] . '.' . $parts[1], $secret), $parts[2])) {
            return array($cookie, hash('sha256', $parts[0]), $parts[1]);
        }
        $id = bin2hex(random_bytes(16));
        $value = $id . '.' . $day;
        return array($value . '.' . hash_hmac('sha256', 'reader:' . $value, $secret), hash('sha256', $id), $day);
    }

    public static function entries(string $file): Generator {
        $handle = fopen($file, 'rb');
        if ($handle === false) { throw new RuntimeException('Could not open statistics sidecar.'); }
        try {
            while (($line = fgets($handle)) !== false) {
                if (!str_ends_with($line, "\n")) { break; }
                $entry = json_decode($line, true);
                if (!is_array($entry) || count($entry) !== 4 || !array_is_list($entry)) { continue; }
                [$day, $reader, $firstDay, $title] = $entry;
                if (is_string($day) && self::validDay($day) && is_string($firstDay) && self::validDay($firstDay)
                    && $firstDay <= $day && is_string($reader) && preg_match('~\A[a-f0-9]{64}\z~', $reader) === 1
                    && is_string($title)) {
                    yield $entry;
                }
            }
            if (!feof($handle)) { throw new RuntimeException('Could not read statistics sidecar.'); }
        } finally {
            fclose($handle);
        }
    }

    public static function line(array $entry): string {
        return json_encode($entry, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE) . "\n";
    }

    public static function counts(array $readers, string $start): array {
        $new = 0;
        foreach ($readers as $firstDay) {
            if ($firstDay >= $start) { $new++; }
        }
        return array('new_readers' => $new, 'returning_readers' => count($readers) - $new, 'total_readers' => count($readers));
    }
}
