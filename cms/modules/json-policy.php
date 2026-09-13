<?php

final class PagecoreJsonDecodeResult {
    public $ok;
    public $value;
    public $error;
    public function __construct($ok, $value = null, $error = null) {
        $this->ok = (bool) $ok;
        $this->value = $value;
        $this->error = $error;
    }
}

/** Named JSON contracts for API responses, reviewed files, and generated indexes. */
final class PagecoreJsonPolicy {
    const MAX_DEPTH = 32;

    private static function encode($value, $substituteInvalidUtf8, $pretty, $depth) {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($substituteInvalidUtf8) { $flags |= JSON_INVALID_UTF8_SUBSTITUTE; }
        if ($pretty) { $flags |= JSON_PRETTY_PRINT; }
        return json_encode($value, $flags, (int) $depth);
    }

    /** Reject invalid UTF-8/depth: appropriate for APIs and human-authored state. */
    public static function encodeStrict($value, $pretty = false, $depth = self::MAX_DEPTH) {
        return self::encode($value, false, $pretty, $depth);
    }

    /** Preserve index availability by replacing invalid UTF-8 with U+FFFD. */
    public static function encodeSubstituting($value, $pretty = false, $depth = self::MAX_DEPTH) {
        return self::encode($value, true, $pretty, $depth);
    }

    private static function decodeRoot($json, $root, $depth) {
        $json = (string) $json;
        $trimmed = ltrim($json);
        if ($trimmed === '') { return new PagecoreJsonDecodeResult(false, null, 'JSON input is empty.'); }
        $first = $trimmed[0];
        if ($root === 'list' && $first !== '[') { return new PagecoreJsonDecodeResult(false, null, 'JSON root must be a list.'); }
        if ($root === 'object' && $first !== '{') { return new PagecoreJsonDecodeResult(false, null, 'JSON root must be an object.'); }
        try {
            $value = json_decode($json, true, (int) $depth, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            return new PagecoreJsonDecodeResult(false, null, $error->getMessage());
        }
        if (!is_array($value)) { return new PagecoreJsonDecodeResult(false, null, 'JSON root must be an array or object.'); }
        if ($root === 'list' && !array_is_list($value)) { return new PagecoreJsonDecodeResult(false, null, 'JSON root must be a list.'); }
        return new PagecoreJsonDecodeResult(true, $value);
    }

    public static function decodeList($json, $depth = self::MAX_DEPTH) {
        return self::decodeRoot($json, 'list', $depth);
    }

    public static function decodeObject($json, $depth = self::MAX_DEPTH) {
        return self::decodeRoot($json, 'object', $depth);
    }
}
