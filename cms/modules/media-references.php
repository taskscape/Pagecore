<?php

final class PagecoreMediaReferences {
    public static function destinations($markdown) {
        $destinations = array();
        if (preg_match_all('~!?\[[^\]]*\]\(\s*(?:<([^>]+)>|([^\s\)]+))~u', (string) $markdown, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) { $destinations[] = $match[1] !== '' ? $match[1] : $match[2]; }
        }
        if (preg_match_all("~(?:^|\\s)pdf:([^\\s\"']+)~iu", (string) $markdown, $matches)) {
            foreach ($matches[1] as $destination) { $destinations[] = $destination; }
        }
        return array_values(array_unique($destinations));
    }

    public static function matches($markdown, array $urls) {
        return (bool) array_intersect(self::destinations($markdown), array_values(array_filter($urls, 'strlen')));
    }
}
