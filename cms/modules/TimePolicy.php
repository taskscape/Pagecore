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

    public static function displayDate($value, $timezone) {
        $date = self::parsePostDate($value, $timezone);
        return $date ? $date->format('j F Y') : (string) $value;
    }
}
