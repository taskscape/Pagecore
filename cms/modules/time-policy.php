<?php

final class PagecoreTimePolicy {
    private const POST_FORMATS = array('Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s');

    public static function timezone($name) {
        try { return new DateTimeZone((string) $name); }
        catch (Throwable $error) { return null; }
    }

    public static function parsePostDate($value, $timezone) {
        $zone = self::timezone($timezone);
        if (!$zone) { return null; }
        foreach (self::POST_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, (string) $value, $zone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format($format) === $value) { return $date; }
        }
        return null;
    }

    public static function now($timezone) { return new DateTimeImmutable('now', self::timezone($timezone) ?: new DateTimeZone('UTC')); }

    public static function formatEpoch($epoch, $format, $timezone) {
        return (new DateTimeImmutable('@' . (int) $epoch))->setTimezone(self::timezone($timezone) ?: new DateTimeZone('UTC'))->format($format);
    }

    /**
     * Format a post date for display.
     *
     * PHP's `F` always renders English month names, so a localized site passes
     * its own twelve names (in the form the language uses for a full date —
     * genitive in Polish: "1 sierpnia 2026"). Without them the English format
     * is kept, so existing sites are unaffected.
     */
    public static function displayDate($value, $timezone, $months = null) {
        $date = self::parsePostDate($value, $timezone);
        if (!$date) { return (string) $value; }
        if (is_array($months) && count($months) === 12) {
            $names = array_values($months);
            $name = $names[(int) $date->format('n') - 1];
            if (is_string($name) && trim($name) !== '') {
                return $date->format('j') . ' ' . $name . ' ' . $date->format('Y');
            }
        }
        return $date->format('j F Y');
    }
}
