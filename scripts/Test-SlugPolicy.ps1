$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$php = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { Join-Path $repoRoot 'php\php.exe' }
& $php (Join-Path $repoRoot 'tests\slug-policy.php')
if ($LASTEXITCODE -ne 0) { throw 'Slug policy checks failed.' }
