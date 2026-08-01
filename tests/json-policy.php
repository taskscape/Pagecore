<?php
require dirname(__DIR__) . '/cms/modules/JsonPolicy.php';

$failures = array();
function json_policy_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$stored = PagecoreJsonPolicy::encodeStrict(array('label' => 'Zażółć', 'items' => array(1, 2)), true);
json_policy_check($stored === "{\n    \"label\": \"Zażółć\",\n    \"items\": [\n        1,\n        2\n    ]\n}", 'Reviewed JSON formatting changed.');

$invalidRejected = false;
try { PagecoreJsonPolicy::encodeStrict(array('value' => "\xB1\x31")); }
catch (JsonException $error) { $invalidRejected = true; }
json_policy_check($invalidRejected, 'Strict encoding accepted invalid UTF-8.');
$substituted = PagecoreJsonPolicy::encodeSubstituting(array('value' => "\xB1\x31"));
json_policy_check(strpos($substituted, "\u{FFFD}1") !== false, 'Index encoding did not substitute invalid UTF-8.');

$list = PagecoreJsonPolicy::decodeList('[{"title":"Post"}]');
json_policy_check($list->ok && $list->value[0]['title'] === 'Post', 'List decoding changed.');
json_policy_check(!PagecoreJsonPolicy::decodeList('{"title":"Post"}')->ok, 'Object was accepted where a list is required.');
json_policy_check(!PagecoreJsonPolicy::decodeObject('[]')->ok, 'List was accepted where an object is required.');
json_policy_check(PagecoreJsonPolicy::decodeObject('{}')->ok, 'Empty object was rejected.');
$malformed = PagecoreJsonPolicy::decodeList('[}');
json_policy_check(!$malformed->ok && $malformed->error !== '', 'Malformed JSON lacked a diagnostic.');

$deep = 'leaf';
for ($index = 0; $index < 40; $index++) { $deep = array($deep); }
$deepJson = json_encode($deep, 0, 512);
json_policy_check(!PagecoreJsonPolicy::decodeList($deepJson)->ok, 'Excessively deep JSON was accepted.');
$deepRejected = false;
try { PagecoreJsonPolicy::encodeStrict($deep); }
catch (JsonException $error) { $deepRejected = true; }
json_policy_check($deepRejected, 'Excessively deep values were encoded.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "JSON policy checks passed.\n";
