<?php

/**
 * Update eligibility, isolated so every rule is decided without network,
 * filesystem, or configuration access. Callers supply the installed build
 * stamp, the published feed, and the environment; this returns a decision.
 */
final class PagecoreUpdatePolicy {
    const SCHEMA = 1;
    const MIN_ARCHIVE_BYTES = 10240;
    const DEFAULT_MAX_ARCHIVE_BYTES = 26214400;

    /** True for a full lowercase git object name. */
    public static function isCommit($value) {
        return is_string($value) && preg_match('~^[0-9a-f]{40}$~', $value) === 1;
    }

    public static function shortCommit($commit) {
        return self::isCommit($commit) ? substr($commit, 0, 7) : '';
    }

    /** Parse an RFC 3339 instant to a UTC timestamp, or null when malformed. */
    public static function instant($value) {
        if (!is_string($value) || $value === '') { return null; }
        $normalized = preg_replace('~[zZ]$~', '+00:00', $value);
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $normalized, new DateTimeZone('UTC'));
        if (!$parsed) { return null; }
        // createFromFormat tolerates out-of-range components; reject anything it had to roll over.
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count']))) { return null; }
        return $parsed->getTimestamp();
    }

    /** Accept only an HTTPS URL whose host is allowlisted. Returns the host or null. */
    public static function allowedHost($url, array $allowedHosts) {
        if (!is_string($url) || $url === '' || strlen($url) > 2048) { return null; }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) { return null; }
        if (strtolower($parts['scheme']) !== 'https') { return null; }
        if (isset($parts['user']) || isset($parts['pass'])) { return null; }
        $host = strtolower($parts['host']);
        foreach ($allowedHosts as $candidate) {
            if (is_string($candidate) && $host === strtolower(trim($candidate))) { return $host; }
        }
        return null;
    }

    /** Validate a decoded build stamp. Returns the normalized identity or null. */
    public static function normalizeBuild($document) {
        if (!is_array($document)) { return null; }
        if (!isset($document['schema']) || (int) $document['schema'] !== self::SCHEMA) { return null; }
        if (!isset($document['version']) || !is_string($document['version']) || !self::isVersion($document['version'])) { return null; }
        if (!self::isCommit(isset($document['commit']) ? $document['commit'] : null)) { return null; }
        $time = self::instant(isset($document['commit_time']) ? $document['commit_time'] : null);
        if ($time === null) { return null; }
        $channel = isset($document['channel']) && is_string($document['channel']) ? $document['channel'] : 'main';
        if (!self::isChannel($channel)) { return null; }
        return array(
            'version' => $document['version'],
            'commit' => $document['commit'],
            'commit_time' => $time,
            'channel' => $channel,
        );
    }

    /** The identity used when no stamp is present: textual version only. */
    public static function unstampedBuild($version) {
        return array('version' => (string) $version, 'commit' => null, 'commit_time' => null, 'channel' => null);
    }

    public static function isVersion($value) {
        return is_string($value) && preg_match('~^[0-9]+\.[0-9]+\.[0-9]+$~', $value) === 1;
    }

    public static function isChannel($value) {
        return $value === 'main';
    }

    /** Validate a decoded feed document. Returns the normalized feed or null. */
    public static function normalizeFeed($document, array $allowedHosts, $maxArchiveBytes = self::DEFAULT_MAX_ARCHIVE_BYTES) {
        if (!is_array($document)) { return null; }
        if (!isset($document['schema']) || (int) $document['schema'] !== self::SCHEMA) { return null; }
        $channel = isset($document['channel']) ? $document['channel'] : null;
        if (!is_string($channel) || !self::isChannel($channel)) { return null; }
        if (!isset($document['version']) || !self::isVersion($document['version'])) { return null; }
        if (!self::isCommit(isset($document['commit']) ? $document['commit'] : null)) { return null; }
        $time = self::instant(isset($document['commit_time']) ? $document['commit_time'] : null);
        if ($time === null) { return null; }
        $archiveUrl = isset($document['archive_url']) ? $document['archive_url'] : null;
        if (self::allowedHost($archiveUrl, $allowedHosts) === null) { return null; }
        $sha = isset($document['archive_sha256']) ? $document['archive_sha256'] : null;
        if (!is_string($sha) || preg_match('~^[0-9a-f]{64}$~', $sha) !== 1) { return null; }
        $bytes = isset($document['archive_bytes']) ? $document['archive_bytes'] : null;
        if (!is_int($bytes) || $bytes < self::MIN_ARCHIVE_BYTES || $bytes > (int) $maxArchiveBytes) { return null; }
        $minPhp = isset($document['min_php']) && is_string($document['min_php']) ? $document['min_php'] : '0.0.0';
        if (!self::isVersion($minPhp)) { return null; }
        $notes = isset($document['notes_url']) ? $document['notes_url'] : null;
        return array(
            'channel' => $channel,
            'version' => $document['version'],
            'commit' => $document['commit'],
            'commit_time' => $time,
            'archive_url' => $archiveUrl,
            'archive_sha256' => $sha,
            'archive_bytes' => $bytes,
            'min_php' => $minPhp,
            'notes_url' => self::allowedHost($notes, $allowedHosts) === null ? null : $notes,
        );
    }

    /**
     * Decide whether $latest may replace $installed.
     *
     * $environment: php_version, channel, allow_downgrade, unattended.
     * Returns array(decision, reason).
     */
    public static function decide(array $installed, $latest, array $environment) {
        if (!is_array($latest) || !isset($latest['commit'], $latest['commit_time'], $latest['channel'])) {
            return self::outcome('malformed', 'The published version feed could not be read.');
        }
        $channel = isset($environment['channel']) ? $environment['channel'] : 'main';
        if ($latest['channel'] !== $channel) {
            return self::outcome('blocked_channel', 'The feed publishes the ' . $latest['channel'] . ' channel; this instance follows ' . $channel . '.');
        }

        $phpVersion = isset($environment['php_version']) ? (string) $environment['php_version'] : PHP_VERSION;
        if (version_compare($phpVersion, $latest['min_php'], '<')) {
            return self::outcome('blocked_php', 'Version ' . $latest['version'] . ' requires PHP ' . $latest['min_php'] . '; this server runs ' . $phpVersion . '.');
        }

        // An unstamped install cannot prove it is older, so it never updates unattended.
        if ($installed['commit'] === null) {
            if (!empty($environment['unattended'])) {
                return self::outcome('blocked_unknown_build', 'This installation has no build stamp, so an unattended update cannot verify it is older.');
            }
            return version_compare($latest['version'], $installed['version'], '<') && empty($environment['allow_downgrade'])
                ? self::outcome('blocked_downgrade', 'The published version ' . $latest['version'] . ' is older than the installed ' . $installed['version'] . '.')
                : self::outcome('available', 'Version ' . $latest['version'] . ' is published.');
        }

        if ($latest['commit'] === $installed['commit']) {
            return self::outcome('up_to_date', 'This instance runs the published build.');
        }
        if ($latest['commit_time'] <= $installed['commit_time']) {
            return self::outcome('blocked_downgrade', 'The published commit is not newer than the installed commit.');
        }
        if (version_compare($latest['version'], $installed['version'], '<') && empty($environment['allow_downgrade'])) {
            return self::outcome('blocked_downgrade', 'The published version ' . $latest['version'] . ' is older than the installed ' . $installed['version'] . '.');
        }
        return self::outcome('available', 'Version ' . $latest['version'] . ' is published.');
    }

    private static function outcome($decision, $reason) {
        return array('decision' => $decision, 'reason' => $reason);
    }

    /**
     * A cron key must be long enough to be unguessable and must not still be
     * the documented placeholder. An empty key disables the endpoint.
     */
    public static function cronKeyConfigured($configured) {
        return is_string($configured)
            && strlen($configured) >= 32
            && stripos($configured, 'REPLACE_WITH') !== 0;
    }

    /** Constant-time key comparison that leaks neither content nor length. */
    public static function cronKeyAccepted($configured, $supplied) {
        if (!self::cronKeyConfigured($configured) || !is_string($supplied) || $supplied === '') { return false; }
        return hash_equals(hash('sha256', $configured), hash('sha256', $supplied));
    }

    /** Human-readable identity: "2.49.0 (232f2c4 · 2026-09-04)". */
    public static function describe(array $identity) {
        if (!isset($identity['version'])) { return 'unknown'; }
        if (empty($identity['commit']) || empty($identity['commit_time'])) {
            return $identity['version'];
        }
        return $identity['version'] . ' (' . self::shortCommit($identity['commit'])
            . ' · ' . gmdate('Y-m-d', (int) $identity['commit_time']) . ')';
    }

    /** The cache-busting token for versioned admin assets. */
    public static function buildId(array $identity) {
        $version = isset($identity['version']) ? (string) $identity['version'] : '0.0.0';
        return empty($identity['commit']) ? $version : $version . '-' . substr($identity['commit'], 0, 12);
    }
}
