<?php

final class PagecoreTemplateDiscovery {
    public static function discover($siteRoot, array $roots, $limit, array $cache = array()) {
        $limit = max(1, (int) $limit);
        $entries = array();
        $keys = array();
        $diagnostics = array();
        $filesSeen = 0;
        $cachedEntries = isset($cache['entries']) && is_array($cache['entries']) ? $cache['entries'] : array();

        foreach ($roots as $relativeRoot) {
            $scanRoot = PagecorePathPolicy::resolveWithin($siteRoot, $relativeRoot === '' ? '.' : $relativeRoot, true);
            if ($scanRoot === null || (!is_dir($scanRoot) && !is_file($scanRoot))) { $diagnostics[] = 'unreadable template root: ' . $relativeRoot; continue; }
            if (is_file($scanRoot)) {
                if (strtolower(pathinfo($scanRoot, PATHINFO_EXTENSION)) !== 'php') { continue; }
                $stack = array(dirname($scanRoot));
                $onlyFile = PagecorePathPolicy::relativeTo($scanRoot, $siteRoot);
            } else {
                $stack = array($scanRoot);
                $onlyFile = null;
            }
            while ($stack && $filesSeen < $limit) {
                $directory = array_pop($stack);
                try { $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS); }
                catch (UnexpectedValueException $error) { $diagnostics[] = 'unreadable template directory'; continue; }
                foreach ($iterator as $file) {
                    $relative = PagecorePathPolicy::relativeTo($file->getPathname(), $siteRoot);
                    if ($onlyFile !== null && $relative !== $onlyFile) { continue; }
                    if ($file->isLink()) { continue; }
                    if ($file->isDir()) { $stack[] = $file->getPathname(); continue; }
                    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') { continue; }
                    $filesSeen++;
                    if ($filesSeen > $limit) { break; }
                    if ($relative === null) { continue; }
                    $signature = $file->getMTime() . ':' . $file->getSize();
                    if (isset($cachedEntries[$relative]['signature']) && $cachedEntries[$relative]['signature'] === $signature) {
                        $fileKeys = isset($cachedEntries[$relative]['keys']) && is_array($cachedEntries[$relative]['keys']) ? $cachedEntries[$relative]['keys'] : array();
                    } else {
                        $source = file_get_contents($file->getPathname());
                        if ($source === false) { $diagnostics[] = 'unreadable template: ' . $relative; continue; }
                        $fileKeys = self::keys((string) $source);
                    }
                    $entries[$relative] = array('signature' => $signature, 'keys' => $fileKeys);
                    foreach ($fileKeys as $key) { $keys[$key] = true; }
                }
                if ($onlyFile !== null) { break; }
            }
        }
        $resultKeys = array_keys($keys);
        sort($resultKeys, SORT_STRING);
        ksort($entries, SORT_STRING);
        return array('keys' => $resultKeys, 'cache' => array('entries' => $entries), 'diagnostics' => array_values(array_unique($diagnostics)), 'truncated' => $filesSeen >= $limit);
    }

    public static function keys($source) {
        $keys = array();
        foreach (array(
            '~cms_editable\(\s*[\'\"]([a-z0-9-]+(?:/[a-z0-9-]+){0,2})[\'\"]~',
            '~data-cms-key\s*=\s*[\'\"]([a-z0-9-]+(?:/[a-z0-9-]+){0,2})[\'\"]~',
        ) as $pattern) {
            if (preg_match_all($pattern, (string) $source, $matches)) {
                foreach ($matches[1] as $key) { $keys[$key] = true; }
            }
        }
        return array_keys($keys);
    }
}
