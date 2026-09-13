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

/** Boot a listed PHP file in a child and JSON-decode stdout. */
function config_source_child($requirePath, array $env, array $server, $afterRequire) {
    global $php;
    $probe = tempnam(sys_get_temp_dir(), 'pagecore-probe-') . '.php';
    $script = '<?php' . PHP_EOL
        . '$server = ' . var_export($server, true) . ';' . PHP_EOL
        . 'foreach ($server as $key => $value) { $_SERVER[$key] = $value; }' . PHP_EOL
        . 'try {' . PHP_EOL
        . '    require ' . var_export($requirePath, true) . ';' . PHP_EOL
        . '    echo json_encode(' . $afterRequire . ');' . PHP_EOL
        . '} catch (Throwable $error) {' . PHP_EOL
        . '    echo json_encode(array("booted" => false, "message" => $error->getMessage(), "display" => defined("PAGECORE_DISPLAY_ERRORS") ? PAGECORE_DISPLAY_ERRORS : false));' . PHP_EOL
        . '}' . PHP_EOL;
    file_put_contents($probe, $script);
    foreach ($env as $name => $value) {
        if ($value === null) { putenv($name); } else { putenv($name . '=' . $value); }
    }
    // error_log() during a rejected boot writes to stderr on CLI; keep it off
    // stdout so the JSON payload stays parseable, and off the suite console.
    $devNull = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
    $output = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($probe) . ' 2>' . $devNull);
    unlink($probe);
    $output = trim((string) $output);
    $start = strrpos($output, '{');
    $decoded = $start === false ? null : json_decode(substr($output, $start), true);
    return is_array($decoded) ? $decoded : array('raw' => $output);
}

// PHP-FPM shape: SetEnv reaches $_SERVER only. The engine must still find it.
$viaServer = config_source_probe(
    array('PAGECORE_CONFIG' => null, 'PAGECORE_DEVELOPMENT' => '1', 'PAGECORE_DISPLAY_ERRORS' => null),
    array('PAGECORE_CONFIG' => $sampleConfig)
);
config_source_check(isset($viaServer['config']) && $viaServer['config'] === $sampleConfig,
    'Config path in $_SERVER was ignored; SetEnv would not work under PHP-FPM.');

// getenv() still wins, and remains the documented mechanism.
$viaEnv = config_source_probe(
    array('PAGECORE_CONFIG' => $sampleConfig, 'PAGECORE_DEVELOPMENT' => '1', 'PAGECORE_DISPLAY_ERRORS' => null),
    array()
);
config_source_check(isset($viaEnv['config']) && $viaEnv['config'] === $sampleConfig,
    'Config path from getenv() was not honoured.');

// The development switch must not be settable through $_SERVER alone.
$devViaServer = config_source_probe(
    array('PAGECORE_CONFIG' => $sampleConfig, 'PAGECORE_DEVELOPMENT' => null, 'PAGECORE_DISPLAY_ERRORS' => null),
    array('PAGECORE_DEVELOPMENT' => '1')
);
config_source_check(isset($devViaServer['raw']) || (isset($devViaServer['development']) && $devViaServer['development'] === false),
    'Development mode was enabled through $_SERVER; the switch must fail closed.');

$generic = config_source_child(
    $repoRoot . '/cms/engine.php',
    array('PAGECORE_CONFIG' => $sampleConfig, 'PAGECORE_DEVELOPMENT' => null, 'PAGECORE_DISPLAY_ERRORS' => null),
    array(),
    'array("booted" => true)'
);
config_source_check(isset($generic['booted']) && $generic['booted'] === false
    && isset($generic['message']) && strpos($generic['message'], 'Check the server error log.') !== false,
    'Production config failures must stay generic without PAGECORE_DISPLAY_ERRORS.');
config_source_check(isset($generic['message']) && strpos($generic['message'], 'demo_credentials') === false
    && strpos($generic['message'], 'require_https') === false,
    'Configuration key names leaked without PAGECORE_DISPLAY_ERRORS.');

$named = config_source_child(
    $repoRoot . '/cms/engine.php',
    array('PAGECORE_CONFIG' => $sampleConfig, 'PAGECORE_DEVELOPMENT' => null, 'PAGECORE_DISPLAY_ERRORS' => '1'),
    array(),
    'array("booted" => true)'
);
config_source_check(isset($named['booted']) && $named['booted'] === false && isset($named['display']) && $named['display'] === true
    && isset($named['message']) && (strpos($named['message'], 'demo_credentials') !== false || strpos($named['message'], 'require_https') !== false),
    'PAGECORE_DISPLAY_ERRORS did not surface the rejected keys.');

$namedViaServer = config_source_child(
    $repoRoot . '/cms/engine.php',
    array('PAGECORE_CONFIG' => $sampleConfig, 'PAGECORE_DEVELOPMENT' => null, 'PAGECORE_DISPLAY_ERRORS' => null),
    array('PAGECORE_DISPLAY_ERRORS' => '1'),
    'array("booted" => true)'
);
config_source_check(isset($namedViaServer['display']) && $namedViaServer['display'] === true
    && isset($namedViaServer['message']) && (strpos($namedViaServer['message'], 'demo_credentials') !== false || strpos($namedViaServer['message'], 'require_https') !== false),
    'PAGECORE_DISPLAY_ERRORS in $_SERVER was ignored; SetEnv would not work under PHP-FPM.');

$tmp = sys_get_temp_dir() . '/pagecore-cfg-src-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$sample = require $sampleConfig;
$sample['site_name'] = 'Bootstrap Probe Site';
$bad = $sample;
$bad['max_content_bytes'] = 0;
file_put_contents($tmp . '/config.php', '<?php return ' . var_export($sample, true) . ";\n");
file_put_contents($tmp . '/bad.php', '<?php return ' . var_export($bad, true) . ";\n");

$devImplied = config_source_child(
    $repoRoot . '/cms/engine.php',
    array('PAGECORE_CONFIG' => $tmp . '/bad.php', 'PAGECORE_DEVELOPMENT' => '1', 'PAGECORE_DISPLAY_ERRORS' => null),
    array(),
    'array("booted" => true)'
);
config_source_check(isset($devImplied['message']) && strpos($devImplied['message'], 'max_content_bytes') !== false,
    'Development mode must imply PAGECORE_DISPLAY_ERRORS.');

$bootstrapEnv = config_source_child(
    $repoRoot . '/sample-site/_bootstrap.php',
    array('PAGECORE_CONFIG' => $tmp . '/config.php', 'PAGECORE_DEVELOPMENT' => '1', 'PAGECORE_DISPLAY_ERRORS' => null),
    array(),
    'array("booted" => true, "site" => cms_cfg("site_name"), "file" => CMS_CONFIG_FILE)'
);
config_source_check(isset($bootstrapEnv['booted']) && $bootstrapEnv['booted'] === true
    && isset($bootstrapEnv['site']) && $bootstrapEnv['site'] === 'Bootstrap Probe Site',
    'sample-site/_bootstrap.php ignored PAGECORE_CONFIG from getenv().');

$bootstrapServer = config_source_child(
    $repoRoot . '/sample-site/_bootstrap.php',
    array('PAGECORE_CONFIG' => null, 'PAGECORE_DEVELOPMENT' => '1', 'PAGECORE_DISPLAY_ERRORS' => null),
    array('PAGECORE_CONFIG' => $tmp . '/config.php'),
    'array("booted" => true, "site" => cms_cfg("site_name"), "file" => CMS_CONFIG_FILE)'
);
config_source_check(isset($bootstrapServer['booted']) && $bootstrapServer['booted'] === true
    && isset($bootstrapServer['site']) && $bootstrapServer['site'] === 'Bootstrap Probe Site',
    'sample-site/_bootstrap.php ignored PAGECORE_CONFIG from $_SERVER.');

$bootstrapFallback = config_source_child(
    $repoRoot . '/sample-site/_bootstrap.php',
    array('PAGECORE_CONFIG' => null, 'PAGECORE_DEVELOPMENT' => '1', 'PAGECORE_DISPLAY_ERRORS' => null),
    array(),
    'array("booted" => true, "file" => CMS_CONFIG_FILE)'
);
config_source_check(isset($bootstrapFallback['file']) && realpath($bootstrapFallback['file']) === realpath($sampleConfig),
    'sample-site/_bootstrap.php did not fall back to its sibling config.php.');

foreach (array($tmp . '/config.php', $tmp . '/bad.php') as $file) {
    if (is_file($file)) { unlink($file); }
}
rmdir($tmp);

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo "Configuration source checks passed.\n";
