<?php
require dirname(__DIR__) . '/cms/modules/OperationalBoundary.php';

$failures = array();
function boundary_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$encodingError = null;
$invalid = PagecoreApiBoundary::encode(array('ok' => true, 'value' => "\xB1\x31"), $encodingError);
boundary_check($encodingError !== null, 'Invalid UTF-8 was not diagnosed.');
boundary_check($invalid === '{"ok":false,"error":"Response encoding failed."}', 'Encoding failure did not return stable client-safe JSON.');
boundary_check(json_decode($invalid, true)['ok'] === false, 'Encoding fallback was not valid JSON.');
$validError = null;
boundary_check(PagecoreApiBoundary::encode(array('ok' => true, 'value' => 'Zażółć'), $validError) === '{"ok":true,"value":"Zażółć"}' && $validError === null, 'Valid Unicode response changed.');

$deep = 'leaf';
for ($index = 0; $index < 40; $index++) { $deep = array($deep); }
$depthError = null;
boundary_check(PagecoreApiBoundary::encode($deep, $depthError) === $invalid && $depthError !== null, 'Deep response did not use the encoding fallback.');

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pagecore-boundary-' . bin2hex(random_bytes(6));
mkdir($root);
$reservedPath = $root . DIRECTORY_SEPARATOR . 'reserved.tmp';
try {
    $reservation = PagecoreOperationalBoundary::reserve($reservedPath, 'test.reserve');
    boundary_check($reservation->ok && is_resource($reservation->value), 'Exclusive reservation failed.');
    if ($reservation->ok) { fclose($reservation->value); }
    boundary_check(!PagecoreOperationalBoundary::reserve($reservedPath, 'test.collision')->ok, 'Existing reservation was overwritten.');
    boundary_check(PagecoreOperationalBoundary::delete($reservedPath, 'test.delete', false)->ok, 'Checked deletion failed.');
    boundary_check(PagecoreOperationalBoundary::delete($reservedPath, 'test.missing')->ok, 'Missing optional target was treated as failure.');
    boundary_check(!PagecoreOperationalBoundary::delete($reservedPath, 'test.required_missing', false)->ok, 'Missing required target was treated as success.');
    boundary_check(!PagecoreOperationalBoundary::delete($root, 'test.directory', false)->ok, 'Filesystem failure was not returned as a typed result.');
} finally { if (is_file($reservedPath)) { unlink($reservedPath); } rmdir($root); }

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Operational boundary checks passed.\n";
