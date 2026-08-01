$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$php = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { 'php' }
& $php (Join-Path $repoRoot 'tests\api-registry.php')
if ($LASTEXITCODE -ne 0) { throw 'API registry checks failed.' }
