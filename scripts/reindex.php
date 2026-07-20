<?php
/**
 * Rebuild the cached posts index, search index and sitemap for a site.
 *
 * Usage:
 *   php scripts/reindex.php /absolute/path/to/config.php
 *   PAGECORE_CONFIG=/path/config.php php scripts/reindex.php
 *
 * Run this after a bulk import or after editing post Markdown files directly
 * on disk (outside the in-browser editor).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "reindex.php must be run from the command line.\n");
    exit(1);
}

$config = isset($argv[1]) ? $argv[1] : getenv('PAGECORE_CONFIG');
if (!$config || !is_file($config)) {
    fwrite(STDERR, "Config file not found. Pass it as the first argument or set PAGECORE_CONFIG.\n");
    exit(1);
}

define('CMS_CONFIG_FILE', $config);
require dirname(__DIR__) . '/cms/engine.php';

$start = microtime(true);
cms_regenerate_indexes();
$posts = cms_posts();
$ms = (int) round((microtime(true) - $start) * 1000);

fwrite(STDOUT, sprintf(
    "Reindexed %d posts in %d ms\n  index:  %s\n  search: %s\n  sitemap:%s\n",
    count($posts),
    $ms,
    cms_posts_index_path(),
    rtrim(cms_cfg('site_url'), '/') . ' -> ' . cms_cfg('site_root') . '/search-index.json',
    cms_cfg('site_root') . '/sitemap.xml'
));
