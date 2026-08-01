<?php
require_once __DIR__ . '/TimePolicy.php';

final class PagecoreFrontMatter {
    private const ORDER = array('title', 'date', 'category', 'status', 'excerpt', 'image', 'tags');
    private const STATUSES = array('publish', 'draft', 'private', 'pending', 'future', 'trash');

    public static function parse($raw, array $categories = array()) {
        $raw = str_replace(array("\r\n", "\r"), "\n", (string) $raw);
        $diagnostics = array();
        if (strncmp($raw, "---\n", 4) !== 0) { return array('meta' => array(), 'body' => $raw, 'diagnostics' => array()); }
        $lines = explode("\n", $raw);
        $end = null;
        for ($index = 1; $index < count($lines); $index++) { if ($lines[$index] === '---') { $end = $index; break; } }
        if ($end === null) { return array('meta' => array(), 'body' => $raw, 'diagnostics' => array('Missing closing front-matter delimiter.')); }
        $meta = array();
        for ($index = 1; $index < $end; $index++) {
            $line = $lines[$index];
            if (trim($line) === '') { continue; }
            if (!preg_match('~^([A-Za-z][A-Za-z0-9_-]*):(?:[ \\t]*(.*))$~', $line, $match)) {
                $diagnostics[] = 'Malformed front-matter line ' . ($index + 1) . '.'; continue;
            }
            $key = strtolower($match[1]);
            if (array_key_exists($key, $meta)) { $diagnostics[] = 'Duplicate front-matter key: ' . $key . '.'; }
            $meta[$key] = self::unquote(trim($match[2]));
        }
        if (isset($meta['date']) && !self::validDate($meta['date'])) { $diagnostics[] = 'Invalid post date.'; }
        if (isset($meta['status']) && !in_array(strtolower($meta['status']), self::STATUSES, true)) { $diagnostics[] = 'Invalid post status.'; }
        if (isset($meta['category']) && $categories && !in_array($meta['category'], $categories, true)) { $diagnostics[] = 'Unknown post category.'; }
        return array('meta' => $meta, 'body' => implode("\n", array_slice($lines, $end + 1)), 'diagnostics' => $diagnostics);
    }

    public static function build(array $meta, $body) {
        $ordered = array();
        foreach (self::ORDER as $key) { if (array_key_exists($key, $meta)) { $ordered[$key] = $meta[$key]; } }
        $unknown = array_diff_key($meta, array_flip(self::ORDER));
        ksort($unknown, SORT_STRING);
        $ordered += $unknown;
        $out = "---\n";
        foreach ($ordered as $key => $value) {
            if (!preg_match('~^[A-Za-z][A-Za-z0-9_-]*$~', (string) $key) || !is_scalar($value)) { continue; }
            $out .= strtolower($key) . ': ' . self::quote(str_replace(array("\r", "\n"), ' ', (string) $value)) . "\n";
        }
        return $out . "---\n" . ltrim(str_replace(array("\r\n", "\r"), "\n", (string) $body), "\n");
    }

    private static function validDate($value) {
        return PagecoreTimePolicy::parsePostDate($value, 'UTC') !== null;
    }

    private static function quote($value) {
        if ($value === '' || $value !== trim($value) || preg_match('~^[\[\]{},&*!|>\'"%@`]~', $value)) { return '"' . addcslashes($value, "\\\"") . '"'; }
        return $value;
    }

    private static function unquote($value) {
        $length = strlen($value);
        if ($length >= 2 && $value[0] === '"' && $value[$length - 1] === '"') { return stripcslashes(substr($value, 1, -1)); }
        if ($length >= 2 && $value[0] === "'" && $value[$length - 1] === "'") { return str_replace("''", "'", substr($value, 1, -1)); }
        return $value;
    }
}
