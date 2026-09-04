<?php

/** Deterministic, filesystem-safe identifiers shared by runtime and migration tools. */
final class PagecoreSlugPolicy {
    const CONTENT_MAX_LENGTH = 120;
    const TAG_MAX_LENGTH = 80;
    const FILENAME_MAX_LENGTH = 80;

    private static function transliterate($value) {
        return strtr((string) $value, array(
            'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
            'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z',
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae','ç'=>'c',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ð'=>'d','ñ'=>'n','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ù'=>'u',
            'ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','þ'=>'th','ÿ'=>'y','ß'=>'ss',
            'À'=>'a','Á'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a','Å'=>'a','Æ'=>'ae','Ç'=>'c',
            'È'=>'e','É'=>'e','Ê'=>'e','Ë'=>'e','Ì'=>'i','Í'=>'i','Î'=>'i','Ï'=>'i',
            'Ð'=>'d','Ñ'=>'n','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o','Ø'=>'o','Ù'=>'u',
            'Ú'=>'u','Û'=>'u','Ü'=>'u','Ý'=>'y','Þ'=>'th',
        ));
    }

    private static function asciiSlug($value) {
        $slug = strtolower(self::transliterate($value));
        $slug = preg_replace('~[^a-z0-9]+~', '-', $slug);
        return trim((string) $slug, '-');
    }

    private static function isReserved($slug) {
        return preg_match('~^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])$~i', (string) $slug) === 1;
    }

    private static function bounded($slug, $maximum) {
        $slug = substr((string) $slug, 0, (int) $maximum);
        return rtrim($slug, '-');
    }

    private static function normalize($value, $fallback, $reservedPrefix, $maximum) {
        $slug = self::asciiSlug($value);
        if ($slug === '') { $slug = $fallback; }
        if ($slug !== '' && self::isReserved($slug)) { $slug = $reservedPrefix . '-' . $slug; }
        return self::bounded($slug, $maximum);
    }

    public static function contentSlug($value) {
        return self::normalize($value, 'post', 'post', self::CONTENT_MAX_LENGTH);
    }

    public static function tagSlug($value) {
        return self::normalize($value, '', 'tag', self::TAG_MAX_LENGTH);
    }

    public static function filenameBase($value) {
        return self::normalize($value, 'file', 'file', self::FILENAME_MAX_LENGTH);
    }

    /** Preserve addressability for existing lowercase ASCII post filenames, including old reserved/long names. */
    public static function isLegacyContentSlug($slug) {
        return preg_match('~^[a-z0-9-]+$~', (string) $slug) === 1;
    }

    public static function contentCandidate($base, $number) {
        $base = self::contentSlug($base);
        $number = max(1, (int) $number);
        if ($number === 1) { return $base; }
        $suffix = '-' . $number;
        return self::bounded($base, self::CONTENT_MAX_LENGTH - strlen($suffix)) . $suffix;
    }

    public static function uniqueContentSlug($value, array &$seen) {
        $base = self::contentSlug($value);
        for ($number = 1; ; $number++) {
            $slug = self::contentCandidate($base, $number);
            if (isset($seen[$slug])) { continue; }
            $seen[$slug] = true;
            return $slug;
        }
    }
}
