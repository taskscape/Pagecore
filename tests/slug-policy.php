<?php
require dirname(__DIR__) . '/cms/modules/SlugPolicy.php';
require dirname(__DIR__) . '/scripts/lib/WordPressImportPolicy.php';

$failures = array();
function slug_policy_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$polish = 'Zażółć gęślą jaźń';
slug_policy_check(PagecoreSlugPolicy::contentSlug($polish) === 'zazolc-gesla-jazn', 'Polish content transliteration changed.');
slug_policy_check(PagecoreWordPressImportPolicy::contentSlug($polish) === PagecoreSlugPolicy::contentSlug($polish), 'Importer and editor content slugs differ.');
slug_policy_check(PagecoreSlugPolicy::contentSlug('Crème brûlée') === 'creme-brulee', 'Latin transliteration changed.');
slug_policy_check(PagecoreSlugPolicy::contentSlug('東京') === 'post', 'Non-ASCII fallback changed.');
slug_policy_check(PagecoreSlugPolicy::tagSlug('') === '', 'Empty tags must remain ignorable.');
slug_policy_check(PagecoreSlugPolicy::tagSlug('CON') === 'tag-con', 'Reserved tag policy changed.');
slug_policy_check(PagecoreSlugPolicy::filenameBase('CON') === 'file-con', 'Reserved filename policy changed.');
slug_policy_check(PagecoreSlugPolicy::filenameBase('  Łódź photo!!.JPG  ') === 'lodz-photo-jpg', 'Filename normalization changed.');

$long = str_repeat('a', 200);
$seen = array();
$first = PagecoreSlugPolicy::uniqueContentSlug($long, $seen);
$second = PagecoreSlugPolicy::uniqueContentSlug($long, $seen);
slug_policy_check(strlen($first) === PagecoreSlugPolicy::CONTENT_MAX_LENGTH, 'Content slug maximum is not enforced.');
slug_policy_check(strlen($second) === PagecoreSlugPolicy::CONTENT_MAX_LENGTH && substr($second, -2) === '-2', 'Collision suffix exceeds the maximum.');
slug_policy_check(PagecoreSlugPolicy::isLegacyContentSlug('con') && PagecoreSlugPolicy::isLegacyContentSlug($long), 'Legacy ASCII slugs lost addressability.');
slug_policy_check(!PagecoreSlugPolicy::isLegacyContentSlug('../post'), 'Unsafe legacy slug was accepted.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Slug policy checks passed.\n";
