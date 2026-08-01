<?php

require dirname(__DIR__) . '/cms/lib/Parsedown.php';

function parsedown_test_fail($message)
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function parsedown_test_assert($condition, $message)
{
    if (!$condition) {
        parsedown_test_fail($message);
    }
}

$allowOutdated = in_array('--allow-outdated', $argv, true);
$parser = new Parsedown();

$basic = $parser->text("# Heading\n\n- one\n- two");
parsedown_test_assert(strpos($basic, '<h1>Heading</h1>') !== false, 'ATX heading rendering changed');
parsedown_test_assert(substr_count($basic, '<li>') === 2, 'list rendering changed');

$safeParser = (new Parsedown())->setSafeMode(true);
$payloads = array(
    '[![nested image](javascript:alert(1))](javascript:alert(2))',
    '> **[nested link](javascript:alert(3))**',
    '- <img src=x onerror="alert(4)">',
    '<a href="javascript:alert(5)" onclick="alert(6)">unsafe</a>',
);

foreach ($payloads as $payload) {
    $rendered = $safeParser->text($payload);
    parsedown_test_assert(
        preg_match('~<[^>]+(?:href|src)\s*=\s*["\']?\s*javascript:~i', $rendered) !== 1,
        "unsafe URL survived safe mode: {$payload}"
    );
    parsedown_test_assert(
        preg_match('~<[^>]+\son(?:error|click)\s*=~i', $rendered) !== 1,
        "event handler survived safe mode: {$payload}"
    );
}

$adversarial = str_repeat('*_', 4000) . 'text' . str_repeat('_*', 4000) . '[';
$startedAt = microtime(true);
$safeParser->text($adversarial);
$elapsed = microtime(true) - $startedAt;
parsedown_test_assert($elapsed < 2.0, sprintf('adversarial emphasis input took %.3f seconds', $elapsed));

if (!$allowOutdated) {
    parsedown_test_assert(Parsedown::version === '1.8.0', 'expected Parsedown 1.8.0, got ' . Parsedown::version);
}

echo sprintf("PASS: Parsedown %s security checks (adversarial input %.3fs)\n", Parsedown::version, $elapsed);
