$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$php = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { 'php' }
$env:PAGECORE_CONFIG = Join-Path $repoRoot 'sample-site\config.php'
$env:PAGECORE_DEVELOPMENT = '1'

& $php (Join-Path $repoRoot 'tests\concurrency.php')
if ($LASTEXITCODE -ne 0) { throw 'Concurrency filesystem checks failed.' }
