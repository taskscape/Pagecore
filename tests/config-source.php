<?php
/**
 * How the engine locates its configuration, and which signals it refuses.
 *
 * Shared hosts point the engine at a private config with `SetEnv` in
 * .htaccess. That reaches getenv() under mod_php/CGI but only $_SERVER under
 * PHP-FPM, so the config path must be readable from both. The development
 * switch deliberately is not: $_SERVER holds a startup snapshot that putenv()
 * cannot clear, so honouring it there would let a stale value pin the engine
 * in development mode — a fail-open security switch.
 */
$repoRoot = dirname(__DIR__);
$php = PHP_BINARY;
$failures = array();

function config_source_check($condition, $message) {
    global $failures;
    if (!$condition) { $failures[] = $message; }
}

/** Boot the engine in a clean child process and report what it resolved. */
function config_source_probe(array $env, array $server) {
    global $php, $repoRoot;
    $probe = tempnam(sys_get_temp_dir(), 'pagecore-probe-') . '.php';
    $script = '<?php' . PHP_EOL
        . '$server = ' . var_export($server, true) . ';' . PHP_EOL
        . 'foreach ($server as $key => $value) { $_SERVER[$key] = $value; }' . PHP_EOL
        . 'require ' . var_export($repoRoot . '/cms/engine.php', true) . ';' . PHP_EOL
        . 'echo json_encode(array("config" => $GLOBALS["cmsConfigFile"], "development" => $GLOBALS["cmsDevelopment"]));' . PHP_EOL;
    file_put_contents($probe, $script);
    // Child processes inherit this process's environment, so putenv() here is
    // what shapes the probe's getenv() — and unsetting works the same way.
    foreach ($env as $name => $value) {
        if ($value === null) { putenv($name); } else { putenv($name . '=' . $value); }
    }
    $output = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($probe) . ' 2>&1');
    unlink($probe);
    $decoded = json_decode(trim((string) $output), true);
    return is_array($decoded) ? $decoded : array('raw' => trim((string) $output));
}

$sampleConfig = $repoRoot . '/sample-site/config.php';

// PHP-FPM shape: SetEnv reaches $_SERVER only. The engine must still find it.
$viaServer = config_source_probe(
    array('PAGECORE_CONFIG' => null, 'PAGECORE_DEVELOPMENT' => '1'),
    array('PAGECORE_CONFIG' => $sampleConfig)
);
config_source_check(isset($viaServer['config']) && $viaServer['config'] === $sampleConfig,
    'Config path in $_SERVER was ignored; SetEnv would not work under PHP-FPM.');

// getenv() still wins, and remains the documented mechanism.
$viaEnv = config_source_probe(
    array('PAGECORE_CONFIG' => $sampleConfig, 'PAGECORE_DEVELOPMENT' => '1'),
    array()
);
config_source_check(isset($viaEnv['config']) && $viaEnv['config'] === $sampleConfig,
    'Config path from getenv() was not honoured.');

// The development switch must not be settable through $_SERVER alone.
$devViaServer = config_source_probe(
    array('PAGECORE_CONFIG' => $sampleConfig, 'PAGECORE_DEVELOPMENT' => null),
    array('PAGECORE_DEVELOPMENT' => '1')
);
config_source_check(isset($devViaServer['raw']) || (isset($devViaServer['development']) && $devViaServer['development'] === false),
    'Development mode was enabled through $_SERVER; the switch must fail closed.');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Configuration source checks passed.\n";
