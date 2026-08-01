<?php
require dirname(__DIR__) . '/cms/modules/Input.php';

$failures = array();
function input_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }
input_check(PagecoreInput::scalarMapError(array('key' => array('value'))) !== null, 'Array input was accepted.');
input_check(PagecoreInput::scalarMapError(array('key' => "\xFF")) !== null, 'Invalid UTF-8 was accepted.');
input_check(PagecoreInput::integer(array('page' => '2'), 'page', 1, 1) === 2, 'Integer parsing changed.');
foreach (array('0', '1.5', 'two') as $invalid) {
    try { PagecoreInput::integer(array('page' => $invalid), 'page', 1, 1); input_check(false, 'Invalid integer was accepted: ' . $invalid); }
    catch (InvalidArgumentException $expected) {}
}
foreach (array('2026-02-29', '2026-13-01', '2026-01-01 25:00') as $invalid) {
    try { PagecoreInput::date($invalid); input_check(false, 'Impossible date was accepted: ' . $invalid); }
    catch (InvalidArgumentException $expected) {}
}
input_check(PagecoreInput::date('2024-02-29 23:59') === '2024-02-29 23:59', 'Valid leap date was rejected.');
if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Input validation checks passed.\n";
