$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$php = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { 'php' }
& $php (Join-Path $repoRoot 'tests\input-validation.php')
if ($LASTEXITCODE -ne 0) { throw 'Input validation checks failed.' }
