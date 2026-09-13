<?php
require_once __DIR__ . '/json-policy.php';
require_once __DIR__ . '/operational-boundary.php';

/**
 * Persistent update state: the cached availability check, the exclusive lock
 * that serializes cron against the admin button, and the short maintenance
 * window held across the directory swap.
 */
final class PagecoreUpdateState {
    const SCHEMA = 1;
    const MAINTENANCE_SECONDS = 120;

    public static function statePath($stateDirectory) {
        return rtrim((string) $stateDirectory, '/\\') . '/update-status.json';
    }

    public static function lockPath($stateDirectory) {
        return rtrim((string) $stateDirectory, '/\\') . '/update.lock';
    }

    public static function maintenancePath($stateDirectory) {
        return rtrim((string) $stateDirectory, '/\\') . '/maintenance.json';
    }

    public static function ensureDirectory($stateDirectory) {
        $directory = rtrim((string) $stateDirectory, '/\\');
        if ($directory === '') { return false; }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) { return false; }
        return is_dir($directory);
    }

    public static function blank() {
        return array(
            'schema' => self::SCHEMA,
            'checked_at' => 0,
            'http_status' => 0,
            'etag' => '',
            'decision' => 'unknown',
            'reason' => '',
            'latest' => null,
            'error' => null,
            'last_attempt_at' => 0,
            'last_apply' => null,
        );
    }

    public static function read($stateDirectory) {
        $path = self::statePath($stateDirectory);
        if (!is_file($path)) { return self::blank(); }
        $decoded = PagecoreJsonPolicy::decodeObject((string) @file_get_contents($path));
        if (!$decoded->ok || !isset($decoded->value['schema']) || (int) $decoded->value['schema'] !== self::SCHEMA) {
            return self::blank();
        }
        return array_merge(self::blank(), $decoded->value);
    }

    /** Replace the state file atomically so a reader never sees a partial write. */
    public static function write($stateDirectory, array $state) {
        if (!self::ensureDirectory($stateDirectory)) { return false; }
        $state['schema'] = self::SCHEMA;
        try { $json = PagecoreJsonPolicy::encodeStrict($state, true); }
        catch (Throwable $error) { return false; }
        $path = self::statePath($stateDirectory);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) { return false; }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            PagecoreOperationalBoundary::delete($temporary, 'update.state.cleanup');
            return false;
        }
        return true;
    }

    /** Non-blocking exclusive lock. Returns a handle, or null when already held. */
    public static function lock($stateDirectory) {
        if (!self::ensureDirectory($stateDirectory)) { return null; }
        $handle = @fopen(self::lockPath($stateDirectory), 'c+b');
        if (!$handle) { return null; }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    public static function unlock($handle) {
        if (!is_resource($handle)) { return; }
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public static function setMaintenance($stateDirectory, $identifier, $now = null) {
        if (!self::ensureDirectory($stateDirectory)) { return false; }
        $now = $now === null ? time() : (int) $now;
        try {
            $json = PagecoreJsonPolicy::encodeStrict(array(
                'until' => $now + self::MAINTENANCE_SECONDS,
                'reason' => 'update',
                'id' => (string) $identifier,
            ));
        } catch (Throwable $error) { return false; }
        return @file_put_contents(self::maintenancePath($stateDirectory), $json . "\n", LOCK_EX) !== false;
    }

    public static function clearMaintenance($stateDirectory) {
        return PagecoreOperationalBoundary::delete(self::maintenancePath($stateDirectory), 'update.maintenance.clear')->ok;
    }

    /**
     * True while a swap is in progress. The window expires on its own so a
     * crashed update cannot leave the admin panel permanently unavailable.
     */
    public static function maintenanceActive($stateDirectory, $now = null) {
        $path = self::maintenancePath($stateDirectory);
        if (!is_file($path)) { return false; }
        $now = $now === null ? time() : (int) $now;
        $decoded = PagecoreJsonPolicy::decodeObject((string) @file_get_contents($path));
        if (!$decoded->ok || !isset($decoded->value['until'])) { return false; }
        return (int) $decoded->value['until'] > $now;
    }

    public static function isStale(array $state, $ttlSeconds, $now = null) {
        $now = $now === null ? time() : (int) $now;
        return ((int) $state['checked_at']) + max(60, (int) $ttlSeconds) <= $now;
    }

    /** Throttle by wall clock before any egress, whatever the caller supplied. */
    public static function throttled(array $state, $minimumInterval, $now = null) {
        $now = $now === null ? time() : (int) $now;
        $last = (int) $state['last_attempt_at'];
        return $last > 0 && $last + max(0, (int) $minimumInterval) > $now && $last <= $now;
    }
}
