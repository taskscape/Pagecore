$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$php = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { Join-Path $repoRoot 'php\php.exe' }
$env:PAGECORE_DEVELOPMENT = '1'
$env:PAGECORE_CONFIG = Join-Path $repoRoot 'sample-site\config.php'
$tests = @(
    'admin-view.php',
    'api-registry.php',
    'config-schema.php',
    'content-cache.php',
    'demo-config-policy.php',
    'front-matter.php',
    'input-validation.php',
    'login-redirect.php',
    'login-throttle.php',
    'media-references.php',
    'modules.php',
    'mutation-operation.php',
    'operational-boundary.php',
    'parsedown-security.php',
    'path-policy.php',
    'private-storage.php',
    'request-guard.php',
    'routes.php',
    'runtime-policy.php',
    'template-discovery.php',
    'time-policy.php',
    'transport-config.php',
    'transport.php',
    'wordpress-import-components.php'
)

$started = Get-Date
$passed = 0
foreach ($test in $tests) {
    $path = Join-Path $repoRoot ('tests\' + $test)
    Write-Host ("[php {0}/{1}] {2}" -f ($passed + 1), $tests.Count, $test)
    & $php $path
    if ($LASTEXITCODE -ne 0) { throw "PHP contract failed: $test" }
    $passed++
}
$elapsed = [Math]::Round(((Get-Date) - $started).TotalSeconds, 2)
Write-Host "PASS: $passed isolated PHP unit/security contracts in $elapsed seconds"
