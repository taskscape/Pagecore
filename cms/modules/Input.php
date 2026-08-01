<?php
require_once __DIR__ . '/TimePolicy.php';

final class PagecoreInput {
    public static function scalarMapError(array $values) {
        foreach ($values as $name => $value) {
            if (!is_string($name) || (!is_scalar($value) && $value !== null)) { return 'Request fields must be scalar values.'; }
            if (is_string($value) && $value !== '' && !preg_match('~~u', $value)) { return 'Request fields must use valid UTF-8.'; }
        }
        return null;
    }

    public static function integer(array $values, $key, $default, $minimum = null, $maximum = null) {
        if (!array_key_exists($key, $values) || $values[$key] === '') { return (int) $default; }
        $parsed = filter_var($values[$key], FILTER_VALIDATE_INT);
        if ($parsed === false || ($minimum !== null && $parsed < $minimum) || ($maximum !== null && $parsed > $maximum)) {
            throw new InvalidArgumentException($key . ' must be a valid integer.');
        }
        return (int) $parsed;
    }

    public static function date($value) {
        if (PagecoreTimePolicy::parsePostDate($value, 'UTC') !== null) { return $value; }
        throw new InvalidArgumentException('Date must be a real calendar date in YYYY-MM-DD format (with optional time).');
    }
}
